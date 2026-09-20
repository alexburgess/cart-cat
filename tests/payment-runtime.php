<?php
/** Run through wp eval-file on an isolated WordPress/Woo database. All Square HTTP is mocked. */
if (!defined('WP_CLI') || !WP_CLI || !function_exists('wc_create_order') || !in_array(wp_parse_url(home_url(), PHP_URL_HOST), array('localhost', '127.0.0.1'), true)) { throw new RuntimeException('Run with WP-CLI against isolated local WooCommerce.'); }
$base = rtrim(getenv('CART_CAT_SOURCE') ?: dirname(__DIR__), '/') . '/cart-cat/includes/';
require_once dirname($base) . '/cart-cat.php';
require_once __DIR__ . '/square-test-provider.php';
require_once __DIR__ . '/square-inventory-fixture.php';
foreach (array('square','payment-lock','payments','gateway') as $part) { require_once $base . 'class-' . $part . '.php'; }
use Cart_Cat\Payments;
use Cart_Cat\Payment_Lock;
use Cart_Cat\Square;
use Cart_Cat\Stock_Hold;
global $assertions, $created, $products, $remote;
$assertions=0; $created=array(); $products=array();
function cc_expect($condition, $message) { global $assertions; $assertions++; if (!$condition) { throw new RuntimeException($message); } }
$user = get_users(array('role'=>'administrator','number'=>1)); wp_set_current_user($user[0]->ID);
$config=array('ready'=>true,'application_id'=>'test-app','location_id'=>'loc-test','environment'=>'sandbox','currency'=>'USD');
add_filter('cart_cat_square_public_config', static function() use (&$config) { return $config; });
add_filter('pre_option_square-woo-sync_settings', static function() { return array('location' => 'loc-test', 'squareAuto' => array('isActive' => true, 'stock' => true)); });
$remote=array('posts'=>0,'orderposts'=>0,'refundposts'=>0,'requests'=>array(),'payments'=>array(),'sqorders'=>array(),'mode'=>'success','reentry'=>null,'refund_mode'=>'success','cards'=>array(array('id'=>'ccof:test','customer_id'=>'customer-test','enabled'=>true,'exp_month'=>12,'exp_year'=>2099,'card_brand'=>'VISA','last_4'=>'1111')),'card_owners'=>array());
add_filter('cart_cat_square_transport', static function($original,$method,$path,$body) use (&$remote) {
    $remote['requests'][]=array($method,$path,$body);
    if ($method==='GET' && strpos($path,'/v2/catalog/object/')===0) {
        $id=explode('?',substr($path,19))[0];
        return isset($remote['catalog'][$id]) ? array('object'=>$remote['catalog'][$id]) : new WP_Error('missing','Synthetic catalog object missing');
    }
    if ($method==='GET' && strpos($path,'/v2/inventory/')===0) {
        $id=substr($path,14);
        return array('counts'=>array(array('catalog_object_id'=>$id,'catalog_object_type'=>'ITEM_VARIATION','location_id'=>'loc-test','state'=>'IN_STOCK','quantity'=>'4','calculated_at'=>gmdate('c'))));
    }
    if ($method==='POST' && $path==='/v2/orders/calculate') {
        $calculated=cc_square_calculate($body['order']);
        if (!empty($remote['corrupt_preview'])) { $calculated['total_tax_money']['amount']++; }
        return array('order'=>$calculated);
    }
    if ($path==='/v2/locations/other-location') return new WP_Error('unavailable','fixture connection unavailable');
    if ($path==='/v2/locations/loc-test') return array('location'=>array('id'=>'loc-test','status'=>'ACTIVE','currency'=>'USD','merchant_id'=>'merchant-test'));
    if (strpos($path,'/v2/cards?')===0) return array('cards'=>$remote['cards']);
    if (strpos($path,'/v2/customers/')===0) return $remote['card_owners'][rawurldecode(substr($path,14))] ?? new WP_Error('customer_unavailable','fixture profile unavailable');
    if ($method==='POST' && $path==='/v2/orders') {
        $remote['orderposts']++;
        if (!empty($remote['observe_order'])) { ($remote['observe_order'])($body); }
        $order=cc_square_calculate($body['order']); $order['id']='sq-' . substr($body['idempotency_key'],0,8);
        if (($remote['corrupt_creation']??'')==='catalog') { $order['line_items'][0]['catalog_object_id']='wrong-variation'; }
        if (($remote['corrupt_creation']??'')==='total') { $order['total_money']['amount']++; }
        $remote['sqorders'][$order['id']]=$order;
        if ($remote['mode']==='order-timeout') { $remote['mode']='success'; return new WP_Error('timeout','simulated'); }
        return array('order'=>$order);
    }
    if ($method==='POST' && $path==='/v2/payments') {
        $remote['posts']++;
        if ($remote['reentry']) { ($remote['reentry'])(); $remote['reentry']=null; }
        if ($remote['mode']==='decline') return new WP_Error('decline','simulated',array('status'=>400,'codes'=>array('card_declined')));
        $payment=$body; unset($payment['source_id']); $payment['id']='p-' . substr($body['idempotency_key'],0,8); $payment['status']='COMPLETED';
        $remote['payments'][$payment['id']]=$payment;
        if ($remote['mode']==='timeout') return new WP_Error('timeout','simulated');
        if ($remote['mode']==='mismatch') $payment['amount_money']['amount']++;
        return array('payment'=>$payment);
    }
    if (strpos($path,'/v2/payments?')===0) return array('payments'=>$remote['mode']==='timeout' ? array() : array_values($remote['payments']));
    if (strpos($path,'/v2/payments/')===0) { $id=substr($path,13); return array('payment'=>$remote['payments'][$id]??array()); }
    if ($path==='/v2/refunds' && $method==='POST') {
        $remote['refundposts']++;
        $r=$body; $r['id']='refund-' . substr($body['idempotency_key'],0,8); $r['status']='COMPLETED'; $remote['refunds'][$r['id']]=$r;
        if ($remote['refund_mode']==='timeout') return new WP_Error('timeout','simulated');
        return array('refund'=>$r);
    }
    if (strpos($path,'/v2/refunds/')===0) return array('refund'=>$remote['refunds'][substr($path,12)]??array());
    throw new RuntimeException('Unexpected Square request '.$method.' '.$path);
},10,4);
function cc_order($total='12.50', $square_customer='') {
    global $created,$products,$remote;
    $p=new WC_Product_Simple(); $p->set_name('Cart Cat payment test'); $p->set_regular_price($total); $p->set_manage_stock(true); $p->set_stock_quantity(5); $p->save(); $products[]=$p->get_id();
    $item_id='ITEM-'.$p->get_id(); $variation_id='VAR-'.$p->get_id();
    $p->update_meta_data('square_product_id',$item_id); $p->update_meta_data('square_variation_id',$variation_id); $p->save();
    $variation=array('id'=>$variation_id,'type'=>'ITEM_VARIATION','version'=>1234,'present_at_all_locations'=>true,'item_variation_data'=>array('item_id'=>$item_id,'track_inventory'=>true));
    $remote['catalog'][$variation_id]=$variation;
    $remote['catalog'][$item_id]=array('id'=>$item_id,'type'=>'ITEM','version'=>1234,'present_at_all_locations'=>true,'item_data'=>array('variations'=>array($variation)));
    $o=wc_create_order(array('created_via'=>'cart_cat','customer_id'=>0)); $created[]=$o->get_id();
    $o->add_product($p,1); $o->set_billing_email('fixture@example.test'); $o->set_billing_first_name('Fixture'); $o->set_billing_last_name('Customer'); $o->set_currency('USD');
    $o->set_payment_method('cart_cat_square'); $o->calculate_totals(false); $o->update_meta_data('_cart_cat_preparation','ready'); $o->update_meta_data('_cart_cat_order','1'); $o->update_meta_data('_cart_cat_version','version-fixture'); $o->save();
    if ($square_customer !== '') { $o->update_meta_data('_cart_cat_square_customer_id',$square_customer); }
    $o->update_meta_data('_cart_cat_square_scope',Square::scope()); $o->update_meta_data('_cart_cat_snapshot',Payments::fingerprint($o)); $o->save(); return $o;
}
function cc_request() { return array('source_type'=>'token','token'=>'cnon:fixture-token','version'=>'version-fixture'); }
try {
    cc_expect(Payments::minor('12.50')===1250,'exact minor unit conversion');
    cc_expect(is_wp_error(Payments::minor('12.501')),'reject fractional minor units');
    cc_expect(is_wp_error(Payments::minor('10.00','EUR')),'unsupported currency fails closed');
    $o=cc_order(); Stock_Hold::establish($o,15); $o->update_meta_data('_cart_cat_expires_at',time()-1); $o->save(); $before=count($remote['requests']);
    $res=Payments::pay($o->get_id(),cc_request(),'customer');
    cc_expect(is_wp_error($res)&&$res->get_error_code()==='cart_cat_order_expired'&&count($remote['requests'])===$before,'expired customer submission never creates a Square attempt or charge');
    $o=cc_order(); Stock_Hold::establish($o,15); $o->update_meta_data('_cart_cat_expires_at',time()+30); $o->save(); $remote['mode']='timeout';
    $remote['observe_order']=static function() use($o) {
        global $wpdb;
        $seconds=(int)$wpdb->get_var($wpdb->prepare("SELECT TIMESTAMPDIFF(SECOND,NOW(),expires) FROM {$wpdb->prefix}wc_reserved_stock WHERE order_id=%d",$o->get_id()));
        cc_expect($seconds>=895,'a recorded near-deadline attempt has protected inventory before the first Square operation');
    };
    $res=Payments::pay($o->get_id(),cc_request()); unset($remote['observe_order']);
    if (is_wp_error($res)) { throw new RuntimeException($res->get_error_code() . ': ' . $res->get_error_message()); }
    cc_expect($res['status']==='checking','a managed hold preserves uncertain capture state');
    $o=wc_get_order($o->get_id()); $o->update_meta_data('_cart_cat_expires_at',time()-1); $o->save(); $before=$remote['posts'];
    $res=Payments::pay($o->get_id(),cc_request(),'customer');
    cc_expect($res['status']==='checking'&&$remote['posts']===$before&&!Stock_Hold::expired(wc_get_order($o->get_id())),'past-deadline checking is reconciled before new-charge expiry checks');
    $remote['mode']='success'; $res=Payments::status($o->get_id());
    cc_expect($res['status']==='paid'&&$remote['posts']===$before,'late captured payment completes once after the customer deadline');
    $o=cc_order(); Stock_Hold::establish($o,15); $o->save(); $remote['mode']='order-timeout';
    $res=Payments::pay($o->get_id(),cc_request());
    $o=wc_get_order($o->get_id()); $o->update_meta_data('_cart_cat_expires_at',time()-1); $o->save(); $before=$remote['posts'];
    $res=Payments::status($o->get_id());
    cc_expect($res['status']==='declined'&&wc_get_order($o->get_id())->has_status('cancelled')&&$remote['posts']===$before,'reconciled order-creation timeout with no charge expires immediately after its deadline');
    foreach (array('FAILED','CANCELED') as $terminal_status) {
        $o=cc_order(); Stock_Hold::establish($o,15); $o->save(); $remote['mode']='timeout'; Payments::pay($o->get_id(),cc_request());
        $o=wc_get_order($o->get_id()); $o->update_meta_data('_cart_cat_expires_at',time()-1); $o->save();
        foreach($remote['payments'] as &$provider_payment) { if(strpos($provider_payment['reference_id'],'cc-'.$o->get_id().'-')===0) { $provider_payment['status']=$terminal_status; } } unset($provider_payment);
        $remote['mode']='success'; $before=$remote['posts']; $res=Payments::status($o->get_id());
        cc_expect($res['status']==='declined'&&wc_get_order($o->get_id())->has_status('cancelled')&&$remote['posts']===$before,'reconciled '.$terminal_status.' payment releases an expired unpaid order without a replacement charge');
    }
    $o=cc_order(); Stock_Hold::establish($o,15); $o->set_status('failed'); $o->save();
    cc_expect(!Stock_Hold::details($o)['active'],'failed native status does not claim an inventory reservation that Woo ignores');
    $remote['observe_order']=static function() use($o) {
        $fresh=wc_get_order($o->get_id()); $line=current($fresh->get_items());
        cc_expect($fresh->has_status('pending')&&wc_get_held_stock_quantity($line->get_product())===1,'a managed failed-order retry restores pending status before provider I/O so Woo counts its inventory hold');
    };
    $res=Payments::pay($o->get_id(),cc_request()); unset($remote['observe_order']);
    cc_expect($res['status']==='paid','the restored failed-order retry completes payment normally');
    $o=cc_order(); $issuer=new ReflectionMethod(\Cart_Cat\Customers::class,'issue_reference'); $ref=$issuer->invoke(null,0,'customer-test');
    foreach(array('location_id'=>'different-location','environment'=>'production','application_id'=>'different-app','currency'=>'CAD') as $field=>$changed) {
        $original=$config[$field]; $config[$field]=$changed; $before=count($remote['requests']);
        $res=Payments::pay($o->get_id(),cc_request(),'customer'); cc_expect(is_wp_error($res)&&$res->get_error_code()==='cart_cat_square_scope_changed','new customer charge rejects changed '.$field);
        cc_expect(is_wp_error(Payments::cards($o->get_id())),'saved cards reject changed '.$field);
        $resolved=\Cart_Cat\Customers::resolve(array('reference'=>$ref),true); cc_expect(is_wp_error($resolved)&&$resolved->get_error_code()==='cart_cat_customer_reference_scope','customer reference rejects changed '.$field);
        cc_expect(count($remote['requests'])===$before,'scope drift sends no Square request for '.$field); $config[$field]=$original;
    }
    delete_transient('cart_cat_customer_'.hash('sha256',$ref));
    $o=cc_order(); $o->update_meta_data('_cart_cat_square_scope',array()); $o->save(); $o->update_meta_data('_cart_cat_snapshot',Payments::fingerprint($o)); $o->save();
    $res=Payments::pay($o->get_id(),cc_request()); cc_expect(is_wp_error($res)&&$res->get_error_code()==='cart_cat_square_scope_missing','unconfigured prepared order requires review before payment');
    $o=cc_order(); wc_reserve_stock_for_order($o); $before=$remote['posts'];
    $remote['reentry']=static function() use ($o) { cc_expect(is_wp_error(Payments::pay($o->get_id(),cc_request(),'customer')),'simultaneous customer/staff acquisition blocked'); };
    $result=Payments::pay($o->get_id(),cc_request()); cc_expect(!is_wp_error($result) && ($result['status']??'')==='paid','success marks paid: '.wp_json_encode($result));
    $fresh=wc_get_order($o->get_id()); cc_expect($fresh->get_status()==='processing','physical order stays processing');
    cc_expect($fresh->get_transaction_id()!=='','transaction saved');
    cc_expect($fresh->get_meta('square_order_id')!=='','canonical square order saved');
    $res=Payments::pay($o->get_id(),cc_request(),'customer'); cc_expect($res['status']==='paid'&&$remote['posts']===$before+1,'repeated staff/customer request charged once');
    $line=current($fresh->get_items()); cc_expect(wc_get_product($line->get_product_id())->get_stock_quantity()===4,'stock reduced exactly once');
    $o=cc_order(); $before=count($remote['requests']); $bad=cc_request(); $bad['version']='stale'; cc_expect(is_wp_error(Payments::pay($o->get_id(),$bad)),'stale version rejected'); cc_expect(count($remote['requests'])===$before,'stale version sends no Square request');
    $o->set_billing_city('changed'); $o->save(); cc_expect(is_wp_error(Payments::pay($o->get_id(),cc_request())),'mutated order snapshot rejected');
    $o=cc_order(); $snapshot=Payments::fingerprint($o);
    foreach(array('_cart_cat_tax_location','_hhh_shipping_preference','_hhh_weather_hold_period_id','_hhh_weather_hold_period_title','_hhh_weather_hold_period_start','_hhh_weather_hold_period_end','_hhh_weather_hold_period_states','_hhh_destination_country','_hhh_destination_state','_hhh_wine_category_id','_hhh_choice_was_required','_hhh_choice_selected_at','_hhh_shipping_preference_label','_hhh_checkout_copy_hash') as $key) {
        $o->update_meta_data($key,'changed'); cc_expect(Payments::fingerprint($o)!==$snapshot,'frozen fulfillment metadata: '.$key); $o->delete_meta_data($key);
    }
    $o->update_meta_data('_cart_cat_tax_location',array('state'=>'changed')); $o->save();
    $before=$remote['posts']; cc_expect(is_wp_error(Payments::pay($o->get_id(),cc_request()))&&$remote['posts']===$before,'changed tax location cannot send a charge');
    $o=cc_order(); $before=$remote['posts']; $remote['mode']='timeout';
    $res=Payments::pay($o->get_id(),cc_request()); cc_expect($res['status']==='checking','capture timeout remains checking');
    cc_expect(!Payments::can_expire(wc_get_order($o->get_id())),'unknown payment cannot expire');
    global $wpdb;
    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}wc_reserved_stock SET expires=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE order_id=%d",$o->get_id()));
    $res=Payments::pay($o->get_id(),cc_request(),'customer'); cc_expect($res['status']==='checking'&&$remote['posts']===$before+1,'unknown repeat never posts a second charge');
    $reservation=$wpdb->get_row($wpdb->prepare("SELECT stock_quantity, expires > NOW() AS active FROM {$wpdb->prefix}wc_reserved_stock WHERE order_id=%d",$o->get_id()),ARRAY_A);
    cc_expect($reservation&&(int)$reservation['active']===1&&(int)$reservation['stock_quantity']===1,'checking refreshes expired native stock reservation');
    $line=current($o->get_items()); cc_expect(wc_get_product($line->get_product_id())->get_stock_quantity()===5,'reservation refresh never deducts physical stock');
    $remote['mode']='success'; $res=Payments::status($o->get_id()); cc_expect($res['status']==='paid','background lookup reconciles captured payment');
    // A captured payment must reconcile even if inventory was exhausted during an outage.
    $o=cc_order(); $remote['mode']='timeout'; Payments::pay($o->get_id(),cc_request()); $before=$remote['posts'];
    $line=current($o->get_items()); $product=wc_get_product($line->get_product_id()); $product->set_stock_quantity(0); $product->set_stock_status('outofstock'); $product->save();
    $res=Payments::status($o->get_id()); cc_expect($res['status']==='checking'&&$remote['posts']===$before,'reservation failure retains existing uncertain payment');
    Payments::status($o->get_id()); $fresh=wc_get_order($o->get_id());
    cc_expect((int)$fresh->get_meta('_cart_cat_stock_hold_review')>0,'failed refresh records durable inventory review');
    $review_notes=array_filter(wc_get_order_notes(array('order_id'=>$o->get_id(),'limit'=>100)),static function($note){return strpos($note->content,'could not refresh the stock reservation')!==false;});
    cc_expect(count($review_notes)===1,'repeated failed refresh adds one review note');
    $remote['mode']='success'; $res=Payments::status($o->get_id()); cc_expect($res['status']==='paid'&&$remote['posts']===$before,'captured payment reconciles despite failed stock keepalive');
    $o=cc_order(); $before=$remote['posts']; $remote['mode']='decline'; $res=Payments::pay($o->get_id(),cc_request()); cc_expect($res['status']==='declined','explicit decline can retry');
    $remote['mode']='success'; $res=Payments::pay($o->get_id(),cc_request()); cc_expect(!is_wp_error($res)&&$res['status']==='paid'&&$remote['posts']===$before+2,'definitive decline permits one new attempt: '.wp_json_encode($res));
    $o=cc_order(); $remote['mode']='mismatch'; $res=Payments::pay($o->get_id(),cc_request()); cc_expect($res['status']==='checking'&&!wc_get_order($o->get_id())->is_paid(),'amount mismatch never marks paid'); $remote['mode']='success';
    $o=cc_order(); $o->update_meta_data('_cart_cat_square_customer_id','customer-test'); $o->save(); $o->update_meta_data('_cart_cat_snapshot',Payments::fingerprint($o)); $o->save();
    $cards=Payments::cards($o->get_id()); cc_expect(count($cards['cards'])===1,'eligible stored card listed'); cc_expect(strpos(wp_json_encode($cards),'ccof:')===false&&strpos(wp_json_encode($cards),'customer-test')===false,'provider identity never exposed in cards');
    $r=array('source_type'=>'card','card_reference'=>$cards['cards'][0]['reference'],'version'=>'version-fixture');
    cc_expect(is_wp_error(Payments::pay($o->get_id(),$r,'customer')),'public stored-card payment prohibited');
    $res=Payments::pay($o->get_id(),$r,'staff'); cc_expect($res['status']==='paid','staff stored card success');
    // Merged Square profiles retain old customer IDs on card records. Only a
    // fresh owner lookup resolving to the selected canonical ID can authorize them.
    $default_cards=$remote['cards']; $alias=$default_cards[0]; $alias['id']='ccof:merged'; $alias['customer_id']='merged-profile';
    $alias_second=$alias; $alias_second['id']='ccof:merged-second'; $alias_second['last_4']='2222';
    $foreign=$alias; $foreign['id']='ccof:foreign'; $foreign['customer_id']='other-profile';
    $disabled=$alias; $disabled['id']='ccof:disabled'; $disabled['customer_id']='disabled-profile'; $disabled['enabled']=false;
    $expired=$alias; $expired['id']='ccof:expired'; $expired['customer_id']='expired-profile'; $expired['exp_year']=2000;
    $remote['cards']=array($alias,$alias_second,$foreign,$disabled,$expired,$default_cards[0]);
    $remote['card_owners']=array('merged-profile'=>array('customer'=>array('id'=>'customer-test','email_address'=>'fixture@example.test')),'other-profile'=>array('customer'=>array('id'=>'different-customer','email_address'=>'fixture@example.test')));
    $o=cc_order('12.50','customer-test'); $before=count($remote['requests']); $cards=Payments::cards($o->get_id());
    cc_expect(!is_wp_error($cards)&&count($cards['cards'])===3,'canonical owner plus verified merged-owner cards are listed; foreign, expired and disabled cards are omitted');
    $lookups=array_filter(array_slice($remote['requests'],$before),static function($request){return strpos($request[1],'/v2/customers/')===0;});
    cc_expect(count($lookups)===2,'merged owner resolution is cached per listing and ineligible cards cause no owner lookup');
    cc_expect(strpos(wp_json_encode($cards),'merged-profile')===false&&strpos(wp_json_encode($cards),'ccof:')===false,'merged profile and provider card IDs never leave the server');
    $r=array('source_type'=>'card','card_reference'=>$cards['cards'][0]['reference'],'version'=>'version-fixture');
    $remote['card_owners']['merged-profile']=array('customer'=>array('id'=>'different-customer','email_address'=>'fixture@example.test')); $before=$remote['posts']; $orderposts=$remote['orderposts'];
    $res=Payments::pay($o->get_id(),$r,'staff'); cc_expect(is_wp_error($res)&&$res->get_error_code()==='cart_cat_card'&&$remote['posts']===$before&&$remote['orderposts']===$orderposts,'same-email owner change after listing is rejected before creating an order or charging');
    $remote['card_owners']['merged-profile']=new WP_Error('timeout','fixture profile timeout');
    $res=Payments::cards($o->get_id()); cc_expect(is_wp_error($res)&&$res->get_error_code()==='cart_cat_card_owner_unavailable','owner lookup failure is reported instead of presenting an unverifiable card');
    $res=Payments::pay($o->get_id(),$r,'staff'); cc_expect(is_wp_error($res)&&$remote['posts']===$before&&$remote['orderposts']===$orderposts,'owner lookup failure prevents the saved-card charge');
    $remote['card_owners']['merged-profile']=array('customer'=>array());
    cc_expect(is_wp_error(Payments::cards($o->get_id())),'missing canonical customer identity fails closed');
    $remote['card_owners']['merged-profile']=array('customer'=>array('id'=>'customer-test','email_address'=>'fixture@example.test')); $before=count($remote['requests']);
    $res=Payments::pay($o->get_id(),$r,'staff'); cc_expect(!is_wp_error($res)&&$res['status']==='paid','verified merged-owner card succeeds through the shared payment service');
    $lookups=array_filter(array_slice($remote['requests'],$before),static function($request){return $request[1]==='/v2/customers/merged-profile';});
    cc_expect(count($lookups)===1,'charging freshly revalidates the alias even after a successful listing');
    $attempt=wc_get_order($o->get_id())->get_meta('_cart_cat_payment_attempt');
    cc_expect($attempt['customer_id']==='customer-test'&&$remote['payments'][$attempt['payment_id']]['customer_id']==='customer-test','payment and durable attempt retain the selected canonical customer');
    $remote['cards']=array($disabled,$expired); $o=cc_order('12.50','customer-test'); $empty_cards=Payments::cards($o->get_id());
    cc_expect($empty_cards['cards']===array()&&strpos($empty_cards['message'],'unexpired')!==false,'unavailable saved cards produce a clear empty-state message');
    $o=cc_order(); $before=count($remote['requests']); $empty_cards=Payments::cards($o->get_id());
    cc_expect($empty_cards['cards']===array()&&strpos($empty_cards['message'],'linked Square customer')!==false&&count($remote['requests'])===$before,'unlinked customers get a clear explanation without provider traffic');
    $remote['cards']=$default_cards; $remote['card_owners']=array();
    $original_config=$config; $config['ready']=false; $config['application_id']=''; $config['location_id']='';
    $o=cc_order('0.00'); $before=count($remote['requests']); $res=Payments::pay($o->get_id(),array('source_type'=>'free','version'=>'version-fixture')); cc_expect($res['status']==='paid'&&count($remote['requests'])===$before,'free completion with no Square connection has no provider traffic'); $config=$original_config;
    $o=cc_order(); $remote['mode']='order-timeout'; $before=$remote['posts']; $res=Payments::pay($o->get_id(),cc_request()); cc_expect($res['status']==='checking','ambiguous order creation checking'); $res=Payments::status($o->get_id()); cc_expect($res['status']==='declined'&&$remote['posts']===$before,'order recovery with no sent payment permits explicit retry');
    $o=cc_order(); $res=Payments::pay($o->get_id(),cc_request());
    $refund=new WC_Order_Refund(); $refund->set_parent_id($o->get_id()); $refund->set_amount(2.50); $refund->save(); $created[]=$refund->get_id();
    Payments::capture_refund($refund,array('order_id'=>$o->get_id()));
    cc_expect(Payments::refund($o->get_id(),'2.50','test refund')===true,'linked refund accepted');
    $intent=wc_get_order($o->get_id())->get_meta('_cart_cat_refund_intent'); cc_expect(!empty($intent['refund_id']),'refund provider id retained');
    $before=$remote['refundposts']; cc_expect(Payments::refund($o->get_id(),'2.50','test refund')===true&&$remote['refundposts']===$before,'uncommitted local refund replay does not create another provider refund');
    // A refund timeout reuses its durable provider idempotency key even if Woo deletes the failed local row.
    $o=cc_order(); Payments::pay($o->get_id(),cc_request());
    $refund=new WC_Order_Refund(); $refund->set_parent_id($o->get_id()); $refund->set_amount(1.25); $refund->save(); $created[]=$refund->get_id();
    Payments::capture_refund($refund,array('order_id'=>$o->get_id())); $remote['refund_mode']='timeout';
    cc_expect(is_wp_error(Payments::refund($o->get_id(),'1.25','timeout fixture')),'uncertain refund is not falsely reported accepted');
    $first_intent=wc_get_order($o->get_id())->get_meta('_cart_cat_refund_intent'); $refund->delete(true);
    $refund=new WC_Order_Refund(); $refund->set_parent_id($o->get_id()); $refund->set_amount(1.25); $refund->save(); $created[]=$refund->get_id();
    Payments::capture_refund($refund,array('order_id'=>$o->get_id())); $remote['refund_mode']='success';
    cc_expect(Payments::refund($o->get_id(),'1.25','timeout fixture')===true,'retry reconciles same linked refund');
    $last_intent=wc_get_order($o->get_id())->get_meta('_cart_cat_refund_intent'); cc_expect($first_intent['id']===$last_intent['id'],'refund idempotency remains unchanged');
    $other=new WC_Order_Refund();$other->set_parent_id($o->get_id());$other->set_amount(1.25);$other->save();$created[]=$other->get_id();Payments::capture_refund($other,array('order_id'=>$o->get_id()));
    cc_expect(is_wp_error(Payments::refund($o->get_id(),'1.25','duplicate')),'second Woo projection blocked while first uncommitted');
    $o=cc_order(); $remote['mode']='timeout'; Payments::pay($o->get_id(),cc_request()); $before=$remote['posts'];
    $config['location_id']='other-location'; $res=Payments::status($o->get_id());cc_expect($res['status']==='checking'&&$remote['posts']===$before,'changed connection cannot re-charge or finalize');$config['location_id']='loc-test';$remote['mode']='success';
    // Webhook rejects unsigned requests, then schedules a re-fetch using only a signed reference.
    $key_before=get_option('cart_cat_square_webhook_signature_key',null);update_option('cart_cat_square_webhook_signature_key','fixture-signature');
    $attempt=wc_get_order($o->get_id())->get_meta('_cart_cat_payment_attempt');
    $body=wp_json_encode(array('data'=>array('object'=>array('payment'=>array('reference_id'=>'cc-'.$o->get_id().'-'.substr(str_replace('-','',$attempt['id']),0,24))))));
    $event=new WP_REST_Request('POST','/cart-cat/v1/square/webhook');$event->set_body($body);
    cc_expect(is_wp_error(Payments::webhook($event)),'unsigned webhook rejected');
    $event->set_header('x-square-hmacsha256-signature',base64_encode(hash_hmac('sha256',rest_url('cart-cat/v1/square/webhook').$body,'fixture-signature',true)));
    cc_expect(Payments::webhook($event) instanceof WP_REST_Response,'signed webhook acknowledged without trusting status');
    if($key_before===null)delete_option('cart_cat_square_webhook_signature_key');else update_option('cart_cat_square_webhook_signature_key',$key_before);
    // Validate exclusion across separate DB connections, not just a request-local busy flag.
    $lock=Payment_Lock::acquire($o->get_id());$peer=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);
    cc_expect((string)$peer->get_var($peer->prepare('SELECT GET_LOCK(%s,0)',$lock[1]))==='0','separate connection cannot acquire payment mutex');
    Payment_Lock::release($lock);$peer->close();
    wp_set_current_user(0); cc_expect(is_wp_error(Payments::cards($o->get_id())),'anonymous cannot list cards'); cc_expect(is_wp_error(Payments::pay($o->get_id(),cc_request(),'staff')),'anonymous cannot use staff route');
    cc_expect(Payments::external_owner(false,wc_get_order($o->get_id()),'refund'),'compatibility owner claimed');
    foreach ($remote['requests'] as $request) { if ($request[0]==='POST'&&$request[1]==='/v2/payments') cc_expect($request[2]['customer_details']['customer_initiated']===true,'customer-requested purchase flags match'); }
    // Native Woo order-pay retains key/capability checks and has no card-on-file UI.
    $guest=cc_order();global $wp_query;$previous_order_pay=$wp_query->get('order-pay');$old_get=$_GET;
    $wp_query->set('order-pay',$guest->get_id());$_GET['key']=$guest->get_order_key();
    $gateway=new \Cart_Cat\Gateway();$available=\Cart_Cat\Gateway::available_gateways(array('cart_cat_square'=>$gateway,'squaresync_credit'=>(object)array()));
    cc_expect(array_keys($available)===array('cart_cat_square'),'guest order-pay exposes only Cart Cat gateway');
    ob_start();$gateway->payment_fields();$html=ob_get_clean();cc_expect(strpos($html,'cart_cat_version')!==false&&strpos($html,'ccof:')===false,'native payment fields include frozen version and no saved cards');
    $guest->update_meta_data('_cart_cat_preparation','editing');$guest->save();
    cc_expect(!$gateway->is_available() && \Cart_Cat\Gateway::available_gateways(array('cart_cat_square'=>$gateway))===array(),'editing order exposes no payable gateway');
    ob_start();$gateway->payment_fields();$editing_html=ob_get_clean();cc_expect($editing_html==='','editing order does not mount customer card fields');
    $guest->update_meta_data('_cart_cat_preparation','ready');$guest->save();
    $_GET['key']='incorrect';cc_expect(\Cart_Cat\Gateway::available_gateways(array('cart_cat_square'=>$gateway,'other'=>(object)array()))===array(),'incorrect order key exposes no gateways');
    $_GET=$old_get;$wp_query->set('order-pay',0);$normal=\Cart_Cat\Gateway::available_gateways(array('cart_cat_square'=>$gateway,'other'=>(object)array()));cc_expect(array_keys($normal)===array('other'),'regular checkout gateways remain unchanged');$wp_query->set('order-pay',$previous_order_pay);
    $remote_order=current($remote['sqorders']); cc_expect(Payments::external_square_owner(false,$remote_order),'early Square import recognized by durable metadata');
    cc_expect(!Payments::external_square_owner(false,array('reference_id'=>'unrelated')),'unrelated Square orders unchanged');
    // Wallet buyer verification uses the same fixed order amount and payment lock.
    $wallet=cc_order();$request=cc_request();$request['verification_token']='verify:wallet-fixture';
    $_GET['key']=$wallet->get_order_key();$_POST=array('woocommerce-pay-nonce'=>wp_create_nonce('woocommerce-pay'),'cart_cat_token'=>$request['token'],'cart_cat_verification_token'=>$request['verification_token'],'cart_cat_version'=>$request['version']);
    $result=$gateway->process_payment($wallet->get_id());
    cc_expect($result['result']==='success','native order-pay forwards wallet token through the shared gateway');
    $payment_calls=array_values(array_filter($remote['requests'],static function($r){return $r[0]==='POST'&&$r[1]==='/v2/payments';}));$sent=end($payment_calls)[2];
    cc_expect($sent['verification_token']==='verify:wallet-fixture'&&$sent['source_id']==='cnon:fixture-token','wallet buyer verification accompanies the one-time Square source');
    cc_expect($sent['amount_money']['amount']===1250&&$sent['customer_details']['seller_keyed_in']===false,'wallet charges the saved amount as customer initiated');
    cc_expect(strpos(wp_json_encode(wc_get_order($wallet->get_id())->get_meta('_cart_cat_payment_attempt')),'verify:wallet-fixture')===false,'buyer verification token is not retained in payment attempt metadata');
    $before=$remote['posts'];$gateway->process_payment($wallet->get_id());cc_expect($remote['posts']===$before,'repeated wallet submission cannot make a second Square charge');
    $invalid=cc_order();$request['verification_token']=array('invalid');$res=Payments::pay($invalid->get_id(),$request,'customer');
    cc_expect(is_wp_error($res)&&$res->get_error_code()==='cart_cat_verification'&&$remote['posts']===$before,'malformed verification fails before any Square order/payment mutation');
    $_GET=$old_get;$_POST=array();
    // Itemization is a payment gate, and known failures never degrade to a generic charge.
    $o=cc_order(); $product=current($o->get_items())->get_product(); $product->delete_meta_data('square_product_id'); $product->save();
    $before=$remote['posts']; $orderposts=$remote['orderposts'];
    $res=Payments::pay($o->get_id(),cc_request(),'customer');
    cc_expect(is_wp_error($res)&&$remote['posts']===$before&&$remote['orderposts']===$orderposts,'missing product mapping blocks both order creation and payment');
    cc_expect(!wc_get_order($o->get_id())->get_meta('_cart_cat_payment_attempt'),'mapping failure creates no ambiguous payment attempt');
    $o=cc_order(); $remote['corrupt_preview']=true;
    $res=Payments::pay($o->get_id(),cc_request(),'customer'); $remote['corrupt_preview']=false;
    cc_expect(is_wp_error($res)&&$remote['posts']===$before&&$remote['orderposts']===$orderposts,'preview tax mismatch blocks order creation despite matching total');
    foreach(array('catalog','total') as $corruption) {
        $o=cc_order(); $remote['corrupt_creation']=$corruption;
        $res=Payments::pay($o->get_id(),cc_request(),'customer'); $remote['corrupt_creation']='';
        cc_expect(is_wp_error($res)&&$res->get_error_code()==='cart_cat_square_itemization'&&$remote['posts']===$before,'created order '.$corruption.' mismatch never sends a payment');
        cc_expect(wc_get_order($o->get_id())->get_meta('_cart_cat_payment_attempt')['state']==='declined','known creation mismatch allows review and a deliberate retry');
    }
    $o=cc_order(); $remote['mode']='order-timeout'; $before=count($remote['requests']);
    $res=Payments::pay($o->get_id(),cc_request(),'customer');
    cc_expect($res['status']==='checking','itemized order timeout is reconciled with its original request');
    $first=wc_get_order($o->get_id())->get_meta('_cart_cat_payment_attempt');
    cc_expect(isset($first['itemization']['order']['line_items'][0]['catalog_object_id']),'the durable attempt freezes itemization before order creation');
    $product=current($o->get_items())->get_product(); $product->update_meta_data('square_product_id','changed-after-timeout'); $product->save();
    $offset=count($remote['requests']); $res=Payments::status($o->get_id());
    $replay=array_values(array_filter(array_slice($remote['requests'],$offset),static function($call){return $call[1]==='/v2/orders';}));
    $original=array_values(array_filter(array_slice($remote['requests'],$before,$offset-$before),static function($call){return $call[1]==='/v2/orders';}));
    cc_expect($res['status']==='declined'&&$replay[0][2]===$original[0][2],'reconciliation replays the byte-for-byte PHP payload even when current product links changed');
    cc_expect(!array_filter(array_slice($remote['requests'],$offset),static function($call){return strpos($call[1],'/v2/catalog/')===0||$call[1]==='/v2/orders/calculate';}),'retry never re-resolves catalog data or recalculates the frozen attempt');
    // An older in-flight total-only attempt retains its original provider request.
    $o=cc_order(); $attempt=array('id'=>wp_generate_uuid4(),'state'=>'creating_order','started'=>time(),'amount'=>1250,'currency'=>'USD','version'=>'version-fixture','fingerprint'=>Payments::fingerprint($o),'context'=>Square::context(),'channel'=>'customer','actor'=>0,'source_type'=>'token','customer_id'=>'','square_order_id'=>'','payment_id'=>'','reconciliations'=>0);
    $o->update_meta_data('_cart_cat_payment_attempt',$attempt); $o->save(); $offset=count($remote['requests']);
    $res=Payments::status($o->get_id()); $legacy=array_values(array_filter(array_slice($remote['requests'],$offset),static function($call){return $call[1]==='/v2/orders';}))[0][2];
    cc_expect($res['status']==='declined'&&$legacy['idempotency_key']===$attempt['id'].'-order','legacy order timeout preserves its original idempotency key');
    cc_expect($legacy['order']['line_items']===array(array('name'=>'WooCommerce order #'.$o->get_order_number(),'quantity'=>'1','base_price_money'=>array('amount'=>1250,'currency'=>'USD'))),'legacy reconciliation preserves its exact total-only line without migration');
    cc_expect(!array_filter(array_slice($remote['requests'],$offset),static function($call){return strpos($call[1],'/v2/catalog/')===0||$call[1]==='/v2/orders/calculate';}),'legacy reconciliation does not contact the catalog or preview APIs');
    echo 'Cart Cat real Woo '.WC_VERSION.' + mocked Square payment tests passed ('.$assertions.' assertions; '.WC_Data_Store::load('order')->get_current_class_name().').'.PHP_EOL;
} finally {
    wp_set_current_user($user[0]->ID);
    foreach(array_reverse($created) as $id) { $o=wc_get_order($id); if($o) { $ref=$o->get_meta('_cart_cat_pending_refund_id'); if($ref) delete_option('_cart_cat_refund_map_'.hash('sha256',$ref)); $o->delete(true); } wp_clear_scheduled_hook('cart_cat_refresh_square_inventory',array($id)); wp_clear_scheduled_hook('cart_cat_reconcile_payment',array($id)); if(function_exists('as_unschedule_all_actions')) as_unschedule_all_actions('cart_cat_reconcile_payment',array($id),'cart-cat'); }
    foreach($products as $id) { $p=wc_get_product($id); if($p) $p->delete(true); }
}
