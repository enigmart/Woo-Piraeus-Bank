<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
// Offline regression harness. No WordPress, live database or bank connections.
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', __DIR__);
define('DB_NAME', 'offline');
class Redirect extends Exception {}
class StopRequest extends Exception {}
class WC_Payment_Gateway { public function get_return_url($o) { return '/received/' . $o->get_id(); } }
class WC_Order {
    public $completed_id = null;
    public $meta = [], $notes = [], $status = 'pending', $paid = null, $completions = 0, $writes = 0;
    function get_id() { return 123; }
    function __call($name,$args) {
        if (strpos($name,'get_') !== 0) throw new Exception($name);
        if (strpos($name,'country')!==false) return 'GR';
        if (strpos($name,'phone')!==false) return '2100000000';
        if ($name==='get_total') return '29.17';
        return 'Test';
    }
    function get_meta($k, $single = true) { return $this->meta[$k] ?? ''; }
    function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    function save() { ++$this->writes; }
    function add_order_note($n, $customer = 0) { $this->notes[] = $n; }
    function is_paid() { return in_array($this->status, ['processing','completed'], true); }
    function get_date_paid() { return $this->paid; }
    function get_status() { return $this->status; }
    function needs_payment() { return in_array($this->status, ['pending','failed'],true); }
    function update_status($s) { $this->status = $s; ++$this->writes; }
    function payment_complete($id) { $this->completed_id = $id; ++$this->completions; $this->status = 'processing'; $this->paid = 'date'; }
    function get_checkout_payment_url($on = false) { return '/pay/123'; }
}
class FakeDB {
    public $last_error = '', $prefix = 'test_', $tickets = [], $locked = false, $deny = false, $releases = 0, $reads = 0, $queryFailure = false;
    function insert($table,$data) { $this->tickets[]=(object)$data; return 1; }
    function prepare($sql, ...$args) { return $sql . ' ' . implode(',', $args); }
    function get_var($sql) {
        if (strpos($sql, 'GET_LOCK') !== false) { if ($this->deny) return 0; $this->locked = true; return 1; }
        if (strpos($sql, 'RELEASE_LOCK') !== false) { $this->locked = false; ++$this->releases; return 1; }
        throw new Exception('Unexpected query');
    }
    function get_results($sql) { ++$this->reads; if (empty($GLOBALS['baseline']) && !$this->locked) throw new Exception('Read without lock'); return $this->queryFailure ? null : array_values(array_filter($this->tickets, fn($t) => ($t->merch_ref ?? '123') === substr($sql, strrpos($sql, ' ') + 1)));  }
    function delete($table, $where, $formats) { $this->tickets = array_values(array_filter($this->tickets, fn($t) => $t->trans_ticket !== $where['trans_ticket'])); }
}
function wc_get_order($id) { global $order, $wpdb; if (empty($GLOBALS['baseline']) && !$wpdb->locked) throw new Exception('Order loaded before lock'); return $id == 123 ? $order : false; }
function WC() { return (object)['session'=>null, 'cart'=>null]; }
function __($s, $domain = '') { return $s; }
function sanitize_text_field($s) { return trim((string)$s); }
function sanitize_key($s) { return $s; }
function wp_unslash($s) { return $s; }
function absint($s) { return abs((int)$s); }
function current_time(...$args) { return '2026-09-14 17:00:00'; }
function wc_get_checkout_url() { return '/checkout'; }
function wp_redirect($url) { throw new Redirect($url); }
function wp_die($message, $title = '', $args = []) { throw new StopRequest((string)($args['response'] ?? 500)); }
function wc_add_notice(...$args) {}
function add_query_arg($k, $v, $url) { return $url . "?" . $k . "=" . $v; }
function is_user_logged_in() { return false; }
class_alias('OfflineApplication', 'Papaki\\PiraeusBank\\WooCommerce\\Application');
class OfflineApplication { const PLUGIN_NAMESPACE = 'offline'; }
$baseline = false;
require dirname(__DIR__) . '/classes/WC_Piraeusbank_Gateway.php';
$reflection = new ReflectionClass('Papaki\\PiraeusBank\\WooCommerce\\WC_Piraeusbank_Gateway');
$gateway = $reflection->newInstanceWithoutConstructor();
foreach (['pb_PosId'=>1,'pb_AcquirerId'=>2,'pb_enable_log'=>'no','pb_order_note'=>'no','redirect_page_id'=>'-1'] as $k=>$v) { $p = $reflection->getProperty($k); $p->setAccessible(true); $p->setValue($gateway,$v); }
function reset_case() { global $order,$wpdb; $order = new WC_Order; $wpdb = new FakeDB; $wpdb->tickets = [(object)['trans_ticket'=>'test-ticket']]; }
function response($overrides = [], $ticket = 'test-ticket') {
    $r = array_merge(['peiraeus'=>'success','ResultCode'=>'0','MerchantReference'=>'123','ResponseCode'=>'00','StatusFlag'=>'Success','SupportReferenceID'=>'456','ApprovalCode'=>'AUTHORISED','Parameters'=>'','TransactionId'=>'789','PaymentMethod'=>'IRIS'], $overrides);
    $r['HashKey'] = strtoupper(hash_hmac('sha256', implode(';', [$ticket,1,2,$r['MerchantReference'],$r['ApprovalCode'],$r['Parameters'],$r['ResponseCode'],absint($r['SupportReferenceID']),$r['AuthStatus']??'',!empty($r['PackageNo'])?absint($r['PackageNo']):'',$r['StatusFlag']]), $ticket));
    return $r;
}
function run_callback($r) { global $gateway; $_REQUEST = $r; try { $gateway->check_piraeusbank_response(); } catch (Redirect $e) { return $e->getMessage(); } catch (StopRequest $e) { return $e->getMessage(); } return 'returned'; }
$checks = 0;
function check($test, $label) { global $checks; if (!$test) throw new Exception('FAIL: '.$label); ++$checks; echo "PASS: $label\n"; }
reset_case();
$r = response();
check(run_callback($r) === '/received/123', 'First IRIS callback succeeds');
check($order->completions === 1 && $order->status === 'processing', 'Payment completes once');
check(count($wpdb->tickets) === 0 && $order->get_meta('_piraeusbank_trans_ticket') === 'test-ticket', 'Consumed ticket retained on order');
$before = serialize($order);
check(run_callback($r) === '/received/123' && serialize($order) === $before, 'Exact retry after ticket deletion has no order side effects');
check(!$wpdb->locked && $wpdb->releases === 2, 'Lock acquired and released on each redirect');
$r['HashKey'] = str_repeat('0',64);
check(run_callback($r) === '/checkout' && serialize($order) === $before, 'Forged hash cannot demote or overwrite a paid order');
check(run_callback(response(['ResultCode'=>'1048'])) === '/checkout' && serialize($order) === $before, 'Late technical failure leaves paid order unchanged');
check(run_callback(['peiraeus'=>'fail','MerchantReference'=>'123','SupportReferenceID'=>'456']) === '/checkout' && serialize($order) === $before, 'Late fail callback leaves paid order unchanged');
check(run_callback(response(['ResponseCode'=>'05','StatusFlag'=>'Failure'])) === '/received/123' && serialize($order) === $before, 'Authenticated late decline cannot undo payment');
$order->status = 'refunded'; $before = serialize($order);
check(run_callback(response()) === '/received/123' && serialize($order) === $before, 'Retry cannot resurrect refunded order');
$order->status = 'failed'; unset($order->meta['_piraeusbank_payment_processed']); $before = serialize($order);
check(run_callback(response()) === '/received/123' && serialize($order) === $before, 'Legacy paid-then-failed order requires explicit reconciliation');
reset_case(); $r = response(); $r['HashKey'] = 'invalid';
run_callback($r);
check($order->completions === 0 && $order->status === 'failed', 'Invalid hash never completes unpaid order');
check($order->get_meta('_piraeusbank_transaction_id') === '', 'Unverified response cannot overwrite transaction identity');
reset_case(); run_callback(response(['ResponseCode'=>'05','StatusFlag'=>'Failure']));
check($order->status === 'failed' && $order->completions === 0, 'Genuine authenticated decline retains failure behavior');
reset_case(); $wpdb->deny = true; $before = serialize($order);
check(run_callback(response()) === '503' && serialize($order) === $before && $wpdb->reads === 0, 'Concurrent lock timeout fails closed before order access');
reset_case(); $wpdb->queryFailure = true; $before = serialize($order);
check(run_callback(response()) === '503' && serialize($order) === $before && !$wpdb->locked, 'Database failure does not mark payment failed and releases lock');
reset_case(); run_callback(response(['PaymentMethod'=>'CARD','AuthStatus'=>'Y','PackageNo'=>'10']));
check($order->status === 'processing' && $order->completions === 1, 'Card HMAC fields continue to verify');
echo "$checks checks passed. No live services used.\n";

function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
reset_case();
$iris_id = '202609241733200000000000055826294';
run_callback(response(['TransactionId'=>$iris_id]));
check($order->completed_id === $iris_id, 'WooCommerce payment_complete receives the exact IRIS identifier');
check($order->get_meta('_piraeusbank_transaction_id') === $iris_id, 'Full IRIS transaction identifier survives verified callback');
check(strpos(implode('\n', $order->notes), $iris_id) !== false, 'IRIS transaction identifier remains exact in order notes');
reset_case();
run_callback(['peiraeus'=>'fail','MerchantReference'=>'123','SupportReferenceID'=>'456','ResultCode'=>'0','ResponseCode'=>'68','PaymentMethod'=>'IRIS']);
check($order->status === 'failed' && $order->completions === 0, 'IRIS timeout does not complete payment');
check(strpos($order->get_meta('_piraeusbank_customer_notice')['message'], 'λήξη χρόνου') !== false, 'Timeout notice explains the bank timeout');
check(strpos(implode('\n', $order->notes), 'ResponseCode=68') !== false, 'Reported failure code preserved in private diagnostic note');
check($order->get_meta('_piraeusbank_transaction_id') === '', 'Failure diagnostics do not replace verified transaction identity');
reset_case();
run_callback(['peiraeus'=>'fail','MerchantReference'=>'123','SupportReferenceID'=>'456','ResultCode'=>'981','ResponseCode'=>'']);
check(strpos($order->get_meta('_piraeusbank_customer_notice')['message'], 'ημερομηνία λήξης') !== false, 'Invalid card or expiry notice is actionable');
reset_case();
run_callback(['peiraeus'=>'fail','MerchantReference'=>'123','ResultCode'=>['981'],'ResponseCode'=>['68']]);
check($order->status === 'failed' && $order->completions === 0, 'Malformed diagnostic fields retain generic failure behavior');
reset_case();
check(run_callback(['peiraeus'=>'cancel']) === '/checkout' && $order->writes === 0, 'Uncorrelated bank backlink does not change order status');
echo "$checks total checks passed. No live services used.\n";

function invoke_private($method, ...$args) { global $reflection,$gateway; $m=$reflection->getMethod($method); $m->setAccessible(true); return $m->invokeArgs($gateway,$args); }
function next_reference() { global $order; return invoke_private('pb_with_order_lock', 123, function() use($order) { return invoke_private('pb_reference_for_payment',$order); }); }
reset_case();
$timeout=response(['peiraeus'=>'fail','StatusFlag'=>'Failure','ResponseCode'=>'68']);
run_callback($timeout);
check($order->get_meta('_piraeusbank_iris_expired_reference') === '123', 'Signed IRIS timeout retires original reference');
check(next_reference() === '123R1', 'First retry after IRIS timeout gets a different merchant reference');
check(next_reference() === '123R1', 'Refresh reuses the current retry reference');
$before=serialize($order);
check(run_callback($timeout) === '/checkout' && serialize($order)===$before, 'Late original timeout cannot invalidate the new attempt');
$wpdb->tickets=[(object)['trans_ticket'=>'retry-ticket','merch_ref'=>'123R1']];
$r=response(['MerchantReference'=>'123R1','TransactionId'=>'202609280000000000000000012345678'], 'retry-ticket');
check(run_callback($r)==='/received/123' && $order->completions===1, 'New reference completes the original WooCommerce order');
$before=serialize($order);
check(run_callback($r)==='/received/123' && serialize($order)===$before, 'Duplicate retry success is idempotent');
check(run_callback($timeout)==='/checkout' && serialize($order)===$before, 'Original timeout cannot undo paid retry');
reset_case(); $r=$timeout; $r['HashKey']='invalid'; run_callback($r);
check($order->get_meta('_piraeusbank_iris_expired_reference')==='' && next_reference()==='123', 'Unverified timeout cannot allocate a new bank payment');
reset_case(); run_callback(response(['peiraeus'=>'fail','StatusFlag'=>'Failure','ResponseCode'=>'68','PaymentMethod'=>'Card']));
check(next_reference()==='123', 'Card failure does not activate IRIS retry behavior');
reset_case(); run_callback($timeout); next_reference();
$wpdb->tickets=[(object)['trans_ticket'=>'retry-ticket','merch_ref'=>'123R1']];
run_callback(response(['peiraeus'=>'fail','StatusFlag'=>'Failure','ResponseCode'=>'68','MerchantReference'=>'123R1'], 'retry-ticket'));
check(next_reference()==='123R2', 'A second verified IRIS timeout creates a second unique retry');
reset_case(); $before=serialize($order);
check(run_callback(response(['MerchantReference'=>'123R1']))==='/checkout' && serialize($order)===$before, 'Unallocated retry reference rejected');
foreach (['123R0','123R01','123R1junk','0123','123-1','9999999999999999999999999999', ['123']] as $bad) {
 check(invoke_private('pb_reference_order_id',$bad)===0, 'Invalid reference rejected: '.json_encode($bad));
}
reset_case(); $wpdb->deny=true;
try { $gateway->generate_piraeusbank_form(123); } catch(StopRequest $e) { check($e->getMessage()==='503' && $order->writes===0, 'Receipt lock contention fails before issuing any ticket'); }
echo "$checks checks including IRIS retries passed.\n";

// Exercise real receipt generation against a local SOAP double: no network access.
class SoapClient {
    public static $requests=[];
    function __construct(...$args) {}
    function IssueNewTicket($xml) {
        self::$requests[]=$xml['Request'];
        return (object)['IssueNewTicketResult'=>(object)['ResultCode'=>0,'TranTicket'=>'issued-ticket']];
    }
}
function get_locale() { return 'el'; }
function esc_url($v) { return $v; }
function esc_attr($v) { return $v; }
function esc_js($v) { return $v; }
function wc_enqueue_js($v) {}
function reset_form_cache() { global $reflection; $p=$reflection->getProperty('pb_issued_forms');$p->setAccessible(true);$p->setValue(null,[]); }
foreach (['pb_authorize'=>'no','pb_ProxyHost'=>'','pb_cardholder_name'=>'no','pb_Username'=>'test','pb_Password'=>'test','pb_PayMerchantId'=>1] as $k=>$v) {
 $p=$reflection->getProperty($k); $p->setAccessible(true); $p->setValue($gateway,$v);
}
reset_case(); reset_form_cache(); run_callback($timeout);
$form=$gateway->generate_piraeusbank_form(123);
check(end(SoapClient::$requests)['MerchantReference']==='123R1', 'SOAP receives the new retry reference');
check(strpos($form,'name="MerchantReference"  value="123R1"')!==false, 'Browser POST uses exactly the SOAP reference');
check(end($wpdb->tickets)->merch_ref==='123R1', 'Issued ticket stored against exact retry reference');
$n=count(SoapClient::$requests); $gateway->generate_piraeusbank_form(123);
check(count(SoapClient::$requests)===$n, 'Duplicate receipt hook does not issue duplicate ticket');
reset_form_cache(); $gateway->generate_piraeusbank_form(123);
check(end(SoapClient::$requests)['MerchantReference']==='123R1', 'Separate receipt refresh keeps same attempt identity');
$r=response(['MerchantReference'=>'123R1'],'issued-ticket');
check(run_callback($r)==='/received/123' && $order->completions===1, 'Generated retry ticket verifies actual callback path');
$n=count(SoapClient::$requests);
check($gateway->generate_piraeusbank_form(123)==='' && count(SoapClient::$requests)===$n, 'Paid order cannot render even a cached payment form');
reset_case(); reset_form_cache(); $order->status='cancelled'; $n=count(SoapClient::$requests);
check($gateway->generate_piraeusbank_form(123)==='' && count(SoapClient::$requests)===$n, 'Cancelled order is not silently reopened or sent to bank');
reset_case(); reset_form_cache(); run_callback($timeout); next_reference(); $before=serialize($order);
$r=response(); $r['HashKey']='invalid';
check(run_callback($r)==='/checkout' && serialize($order)===$before, 'Malformed old success cannot fail new attempt');
echo "$checks TOTAL CHECKS PASSED; no live bank requests.\n";
