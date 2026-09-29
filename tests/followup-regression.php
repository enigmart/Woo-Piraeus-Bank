<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__); define('DB_NAME','test'); define('WSDL_CACHE_NONE',0);
require dirname(__DIR__).'/classes/FollowUp.php';
use Papaki\PiraeusBank\WooCommerce\FollowUp;
$settings=['pb_PayMerchantId'=>'123','pb_PosId'=>'456','pb_Username'=>'test','pb_Password'=>'secret','pb_authorize'=>'no'];
function get_option($key,$default=[]) { return $GLOBALS['settings']; }
$jobs=[];
function as_get_scheduled_actions($q,$format) { return array_filter($GLOBALS['jobs'],fn($j)=>$j['args']===$q['args']); }
function as_schedule_single_action($time,$hook,$args,$group) { $GLOBALS['jobs'][]=compact('time','hook','args','group'); }
class DB {
 public $prefix='wp_', $last_error='', $locked=false, $deny=false, $ticket=null;
 function prepare($q,...$a) { return $q; }
 function get_var($q) {
  if(strpos($q,'GET_LOCK')!==false) { if($this->deny)return 0; $this->locked=true;return 1; }
  if(strpos($q,'RELEASE_LOCK')!==false) { $this->locked=false;return 1; }
  return $this->ticket;
 }
}
class Order {
 public $meta=[], $status='pending',$notes=[], $complete=0,$tx='', $paid=false,$method='piraeusbank_gateway',$total='29.17';
 function get_id(){return 1668;} function get_meta($k,$single=true){return $this->meta[$k]??'';}
 function update_meta_data($k,$v){$this->meta[$k]=$v;} function delete_meta_data($k){unset($this->meta[$k]);}
 function save(){} function add_order_note($n){$this->notes[]=$n;} function get_total(){return $this->total;}
 function get_currency(){return 'EUR';} function get_payment_method(){return $this->method;}
 function get_status(){return $this->status;} function is_paid(){return in_array($this->status,['processing','completed']);}
 function get_date_paid(){return $this->paid;} function get_transaction_id(){return $this->tx;}
 function payment_complete($tx){++$this->complete;$this->tx=$tx;$this->status='processing';$this->paid=true;}
 function update_status($s,$note=''){$this->status=$s;}
}
function wc_get_order($id){if(!$GLOBALS['wpdb']->locked)throw new Exception('Order loaded outside lock');return $GLOBALS['order'];}
class Probe extends FollowUp {
 static $result, $calls=0, $throw=false;
 protected static function fetch($ref,$s){++self::$calls;if(self::$throw)throw new Exception('network');return self::$result;}
}
function fixture($status='Success',$code='00') {
 return json_decode(json_encode(['Header'=>['RequestType'=>'FOLLOW_UP','ResultCode'=>0,'SupportReferenceID'=>668490911,'MerchantInfo'=>['MerchantID'=>123,'PosID'=>456]],'Body'=>['TransactionInfo'=>['MerchantReference'=>'1668','StatusFlag'=>$status,'ResponseCode'=>$code,'TransactionID'=>1377602]]]));
}
function reset_case(){global $wpdb,$order,$jobs;$wpdb=new DB;$order=new Order;$jobs=[];Probe::$calls=0;Probe::$throw=false;Probe::$result=fixture();Probe::ticket_issued($order,'1668');$jobs=[];}
$n=0;function check($v,$s){global $n;if(!$v)throw new Exception($s);++$n;echo "PASS $s\n";}
reset_case();Probe::poll(1668);
check($order->complete===1,'Missing callback recovered by poll');
check($order->tx==='' && $order->meta['_piraeusbank_followup_transaction_id']==='1377602','SOAP ID never substituted for IRIS ID');
Probe::poll(1668);check($order->complete===1 && Probe::$calls===1,'Duplicate poll cannot complete twice');
check(!$wpdb->locked,'Lock released after success');
reset_case();$order->tx='202609270905530000000000056008019';Probe::poll(1668);check($order->tx==='202609270905530000000000056008019','Existing long IRIS ID preserved');
reset_case();Probe::$result->Body->TransactionInfo->IRISTransactionID='202609270905530000000000056008019';Probe::poll(1668);check($order->tx==='202609270905530000000000056008019','Returned IRIS ID preserved as string');
reset_case();$ret=Probe::cancel_unpaid(true,$order);check($ret===false && $order->status==='processing','Cancellation reconciles paid order and blocks stale WC write');
reset_case();Probe::$result=fixture('Failure','68');check(Probe::cancel_unpaid(true,$order)===false && $order->status==='cancelled','Confirmed timeout cancels under lock');
reset_case();Probe::$throw=true;Probe::cancel_unpaid(true,$order);check($order->status==='pending' && count($jobs)===1,'Service outage blocks cancellation and schedules retry');
Probe::cancel_unpaid(true,$order);check(count($order->notes)===1,'Review note is deduplicated');
reset_case();Probe::$result=fixture('Pending','');Probe::poll(1668);check($order->complete===0 && count($jobs)===1,'Pending reschedules without payment');
reset_case();Probe::$result->Header->ResultCode=1010;check(!Probe::before_payment($order) && $order->complete===0 && !$order->get_meta('_piraeusbank_iris_expired_reference'),'1010 does not rotate reference');
reset_case();Probe::$result=fixture('Failure','68');check(Probe::before_payment($order) && $order->meta['_piraeusbank_iris_expired_reference']==='1668','Verified timeout permits existing retry allocator');
reset_case();Probe::$result=fixture('Success','10');Probe::poll(1668);check($order->complete===0,'Partial approval never fulfils entire order');
foreach(['MerchantID','PosID'] as $field){reset_case();Probe::$result->Header->MerchantInfo->$field=999;Probe::poll(1668);check($order->complete===0,"Reject different $field");}
reset_case();Probe::$result->Body->TransactionInfo->MerchantReference='1668R1';Probe::poll(1668);check($order->complete===0,'Wrong attempt rejected');
reset_case();$order->total='50.00';Probe::poll(1668);check($order->complete===0 && Probe::$calls===0,'Edited order amount requires review');
reset_case();$order->meta['_piraeusbank_followup_attempt']['sale']=false;Probe::poll(1668);check($order->complete===0,'Preauthorisation never treated as captured payment');
reset_case();$order->status='refunded';Probe::poll(1668);check(Probe::$calls===0 && $order->status==='refunded','Refunded orders untouched');
reset_case();$order->status='cancelled';Probe::poll(1668);check(Probe::$calls===0,'Historical cancellations not revived');
reset_case();$order->paid=true;$order->status='failed';Probe::poll(1668);check(Probe::$calls===0,'Paid-then-failed history not revived');
reset_case();$order->method='bacs';check(Probe::cancel_unpaid(true,$order)===true && Probe::$calls===0,'Other gateways unaffected');
reset_case();$wpdb->deny=true;Probe::poll(1668);check(Probe::$calls===0 && count($jobs)===1,'Lock contention reschedules without bank call');
reset_case();$order->meta=[];$wpdb->ticket=1;check(!Probe::before_payment($order) && $order->complete===0,'Untracked historical success requires reconciliation');
reset_case();$order->meta=[];$wpdb->ticket=1;Probe::$result=fixture('Failure','68');check(Probe::before_payment($order),'Untracked historical confirmed timeout can retry');
reset_case();$order->meta=[];check(Probe::before_payment($order) && Probe::$calls===0,'Brand new payment skips follow-up');
reset_case();Probe::$result=null;Probe::poll(1668);check($order->complete===0 && count($jobs)===1,'Malformed reply is unknown');
foreach (['05','12','51','34','43','54','62','92','I2'] as $code) { reset_case(); Probe::$result=fixture('Failure',$code); check(Probe::before_payment($order), 'Documented decline permits retry: '.$code); }
reset_case(); Probe::$result=fixture('Failure','XX'); check(!Probe::before_payment($order), 'Unknown failure code never permits retry');
// Exercise the real transport builder without network access.
class SoapClient {
 static $request;
 function __construct($wsdl,$options){if($options['trace']!==false || !$options['stream_context'])throw new Exception('Unsafe transport');}
 function ProcessTransaction($r){self::$request=$r;return (object)['TransactionResponse'=>fixture()];}
}
reset_case();FollowUp::poll(1668);$r=SoapClient::$request['TransactionRequest'];
check($r['Header']['RequestType']==='FOLLOW_UP' && $r['Header']['RequestMethod']==='SYNCHRONOUS','Transport only sends FOLLOW_UP');
check($r['Header']['MerchantInfo']['AcquirerID']==='GR014' && $r['Header']['MerchantInfo']['Password']===md5('secret'),'Correct Web Service credentials format');
check(array_keys($r['Body']['TransactionInfo'])===['MerchantReference'],'No financial fields sent');
echo "$n checks passed\n";
