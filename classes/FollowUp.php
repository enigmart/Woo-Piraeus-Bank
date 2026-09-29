<?php
namespace Papaki\PiraeusBank\WooCommerce;
if (!defined('ABSPATH')) { exit; }

/** Server-side reconciliation. Never sends a financial operation to the bank. */
class FollowUp {
    const HOOK = 'piraeusbank_follow_up';
    const GROUP = 'piraeusbank';

    public static function register() {
        add_action(self::HOOK, [static::class, 'poll'], 10, 1);
        add_filter('woocommerce_cancel_unpaid_order', [static::class, 'cancel_unpaid'], 20, 2);
    }

    private static function settings() { return get_option('woocommerce_piraeusbank_gateway_settings', []); }
    private static function reference($order) { return (string)($order->get_meta('_piraeusbank_active_reference', true) ?: $order->get_id()); }
    private static function paid($order) { return $order->is_paid() || $order->get_date_paid() || $order->get_meta('_piraeusbank_payment_processed', true); }

    /** Called under the receipt lock after a ticket is persisted, before redirection. */
    public static function ticket_issued($order, $reference) {
        $s = static::settings();
        if (($s['pb_authorize'] ?? 'no') === 'yes') { return; }
        $order->update_meta_data('_piraeusbank_followup_attempt', [
            'reference'=>(string)$reference, 'total'=>(string)$order->get_total(),
            'currency'=>$order->get_currency(), 'merchant'=>(string)$s['pb_PayMerchantId'],
            'pos'=>(string)$s['pb_PosId'], 'sale'=>($s['pb_authorize'] ?? 'no') !== 'yes', 'started'=>time(),
        ]);
        $order->delete_meta_data('_piraeusbank_followup_result');
        $order->save();
        static::schedule($order->get_id(), 300);
    }

    public static function schedule($id, $delay) {
        // Not unique: a currently running Action Scheduler job must be able to enqueue its successor.
        $args = [(int)$id];
        if (function_exists('as_schedule_single_action')) {
            if (!as_get_scheduled_actions(['hook'=>self::HOOK, 'args'=>$args, 'group'=>self::GROUP, 'status'=>'pending', 'per_page'=>1], 'ids')) {
                as_schedule_single_action(time() + $delay, self::HOOK, $args, self::GROUP);
            }
        } elseif (!wp_next_scheduled(self::HOOK, $args)) {
            wp_schedule_single_event(time() + $delay, self::HOOK, $args);
        }
    }

    /** Same advisory lock name as receipt generation and authenticated callbacks. */
    private static function locked($id, $operation) {
        global $wpdb;
        $name = 'pb_callback_' . md5(DB_NAME . ':' . $wpdb->prefix . ':' . (int)$id);
        if ('1' !== (string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name))) {
            static::schedule($id, 60);
            return;
        }
        try { $order = wc_get_order($id); if ($order) { $operation($order); } }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); }
    }

    public static function poll($id) {
        static::locked($id, function ($order) {
            if ($order->get_payment_method() !== 'piraeusbank_gateway' || static::paid($order)) { return; }
            if (!in_array($order->get_status(), ['pending','failed'], true)) { return; }
            $result = static::reconcile_locked($order);
            if (!in_array($result, ['paid','failure','untracked'], true)) {
                static::schedule($order->get_id(), 900);
            }
        });
    }

    /** Cancel under our lock and return false, preventing WC from writing a stale order outside it. */
    public static function cancel_unpaid($cancel, $order) {
        if ((static::settings()['pb_authorize'] ?? 'no') === 'yes') { return $cancel; }
        if (!$cancel || $order->get_payment_method() !== 'piraeusbank_gateway') { return $cancel; }
        if (!static::has_ticket($order)) { return $cancel; }
        static::locked($order->get_id(), function ($fresh) {
            if ($fresh->get_payment_method() !== 'piraeusbank_gateway' || $fresh->get_status() !== 'pending' || static::paid($fresh)) { return; }
            $result = static::reconcile_locked($fresh);
            if ($result === 'failure') {
                $fresh->update_status('cancelled', 'Piraeus FOLLOW_UP confirmed terminal payment failure before automatic cancellation.');
            } elseif ($result !== 'paid') {
                static::review($fresh);
                static::schedule($fresh->get_id(), 900);
            }
        });
        return false;
    }

    /** Receipt already owns the common lock. Unknown results must not create another payment. */
    public static function before_payment($order) {
        if ((static::settings()['pb_authorize'] ?? 'no') === 'yes') { return true; }
        if (!static::has_ticket($order)) { return true; }
        $result = static::reconcile_locked($order);
        if ($result === 'failure') {
            // This marker is also consumed by the existing authenticated IRIS timeout retry path.
            $order->update_meta_data('_piraeusbank_iris_expired_reference', static::reference($order));
            $order->save();
            return true;
        }
        if ($result !== 'paid') { static::schedule($order->get_id(), 300); }
        return false;
    }

    private static function has_ticket($order) {
        if ($order->get_meta('_piraeusbank_followup_attempt', true)) { return true; }
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'piraeusbank_transactions WHERE merch_ref = %s LIMIT 1', static::reference($order)));
        return $wpdb->last_error !== '' || $found !== null;
    }

    private static function review($order) {
        if (!$order->get_meta('_piraeusbank_followup_review', true)) {
            $order->update_meta_data('_piraeusbank_followup_review', 1);
            $order->save();
            $order->add_order_note('Piraeus: payment outcome requires reconciliation. Automatic cancellation/new payment is paused until a definitive bank result. Check the merchant reference in epay.');
        }
    }

    /** Only the caller holding the common order lock may call this method. */
    public static function reconcile_locked($order) {
        if (static::paid($order)) { return 'paid'; }
        $attempt = $order->get_meta('_piraeusbank_followup_attempt', true);
        $ref = static::reference($order);
        if (!is_array($attempt) || ($attempt['reference'] ?? '') !== $ref) {
            // Historical orders have no immutable amount/configuration snapshot: never auto-fulfil them.
            try { $r = static::fetch($ref, static::settings()); } catch (\Throwable $e) { $r = null; }
            if (static::classify($r, $ref, static::settings()) === 'failure') { return 'failure'; }
            static::review($order);
            return 'untracked';
        }
        $s = static::settings();
        if (($attempt['total'] ?? '') !== (string)$order->get_total() || ($attempt['currency'] ?? '') !== $order->get_currency()
            || ($attempt['merchant'] ?? '') !== (string)($s['pb_PayMerchantId'] ?? '')
            || ($attempt['pos'] ?? '') !== (string)($s['pb_PosId'] ?? '') || empty($attempt['sale'])) {
            static::review($order);
            return 'unknown';
        }
        try { $r = static::fetch($ref, $s); }
        catch (\Throwable $e) { $r = null; }
        $result = static::classify($r, $ref, $s);
        $order->update_meta_data('_piraeusbank_followup_result', [
            'reference'=>$ref, 'checked'=>time(), 'outcome'=>$result,
            'result_code'=>isset($r->Header->ResultCode) ? (string)$r->Header->ResultCode : '',
            'response_code'=>isset($r->Body->TransactionInfo->ResponseCode) ? (string)$r->Body->TransactionInfo->ResponseCode : '',
        ]);
        if ($r && isset($r->Header->SupportReferenceID)) {
            $order->update_meta_data('_piraeusbank_followup_support_reference_id', (string)$r->Header->SupportReferenceID);
        }
        $order->save();
        if ($result === 'success') {
            $t = $r->Body->TransactionInfo;
            // The SOAP integer ID and the long IRIS ID are distinct; never substitute one for the other.
            $order->update_meta_data('_piraeusbank_followup_transaction_id', (string)$t->TransactionID);
            $iris = isset($t->IRISTransactionID) ? (string)$t->IRISTransactionID : '';
            if ($iris !== '') { $order->update_meta_data('_piraeusbank_iris_transaction_id', $iris); }
            $order->save();
            if (!in_array($order->get_status(), ['pending','failed'], true)) { static::review($order); return 'unknown'; }
            $order->payment_complete($order->get_transaction_id() ?: $iris);
            $order->update_meta_data('_piraeusbank_payment_processed', 1);
            $order->delete_meta_data('_piraeusbank_followup_review');
            $order->save();
            $order->add_order_note('Payment confirmed by Piraeus FOLLOW_UP. MerchantReference: ' . $ref . '; SupportReferenceID: ' . (string)$r->Header->SupportReferenceID . '; Web Service TransactionID: ' . (string)$t->TransactionID);
            return 'paid';
        }
        if (time() - (int)($attempt['started'] ?? 0) >= 86400) { static::review($order); }
        return $result;
    }

    /** A failed lookup (including 1010), partial approval or missing data is never a terminal failure. */
    public static function classify($r, $ref, $s) {
        if (!is_object($r) || ($r->Header->RequestType ?? '') !== 'FOLLOW_UP'
            || (string)($r->Header->ResultCode ?? '') !== '0'
            || (string)($r->Header->MerchantInfo->MerchantID ?? '') !== (string)$s['pb_PayMerchantId']
            || (string)($r->Header->MerchantInfo->PosID ?? '') !== (string)$s['pb_PosId']
            || (string)($r->Body->TransactionInfo->MerchantReference ?? '') !== $ref) { return 'unknown'; }
        $t = $r->Body->TransactionInfo;
        if (($t->StatusFlag ?? '') === 'Success' && in_array((string)($t->ResponseCode ?? ''), ['00','08','16'], true)
            && preg_match('/^[1-9][0-9]*$/D', (string)($t->TransactionID ?? ''))
            && preg_match('/^[1-9][0-9]*$/D', (string)($r->Header->SupportReferenceID ?? ''))) { return 'success'; }
        // Documented declines (manual pp. 67-68), plus IRIS timeout verified with epay.
        if (($t->StatusFlag ?? '') === 'Failure' && in_array((string)($t->ResponseCode ?? ''), ['05','12','51','34','43','54','62','92','I2','68'], true)) { return 'failure'; }
        return 'unknown';
    }

    protected static function fetch($reference, $s) {
        $client = new \SoapClient(dirname(__DIR__) . '/resources/paymentgateway.wsdl', [
            'exceptions'=>true, 'trace'=>false, 'connection_timeout'=>10, 'cache_wsdl'=>WSDL_CACHE_NONE,
            'stream_context'=>stream_context_create(['http'=>['timeout'=>60], 'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]),
        ]);
        return $client->ProcessTransaction(['TransactionRequest'=>[
            'Header'=>['RequestType'=>'FOLLOW_UP','RequestMethod'=>'SYNCHRONOUS','MerchantInfo'=>[
                'AcquirerID'=>'GR014','MerchantID'=>(int)$s['pb_PayMerchantId'],'PosID'=>(int)$s['pb_PosId'],
                'ChannelType'=>'eCommerce','User'=>$s['pb_Username'],'Password'=>md5($s['pb_Password']),
            ]], 'Body'=>['TransactionInfo'=>['MerchantReference'=>(string)$reference]],
        ]])->TransactionResponse;
    }
}
