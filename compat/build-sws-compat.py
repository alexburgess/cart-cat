#!/usr/bin/env python3
"""Create a separate reviewed SWS copy/patch. Never modifies the source installation."""
import argparse, difflib, hashlib, json, pathlib, re, shutil
p=argparse.ArgumentParser(); p.add_argument('--source', required=True); p.add_argument('--output', required=True); p.add_argument('--patch'); a=p.parse_args()
src=pathlib.Path(a.source).resolve(); out=pathlib.Path(a.output).resolve()
if src == out or src in out.parents or out.exists(): raise SystemExit('Output must be a new directory outside the source.')
changes={}
def read(name):
    return changes.get(name, (src/name).read_text())
def replace(name, old, new, count=1):
    text=read(name)
    if text.count(old)!=count: raise SystemExit(f'Unexpected source in {name}: anchor count {text.count(old)} != {count}; review this version before patching.')
    changes[name]=text.replace(old,new)
def guard(name, signature, var='$order', operation='payment', returns='return;'):
    text=read(name)
    pattern=re.escape(signature)+r'\s*\{'
    matches=list(re.finditer(pattern,text))
    if len(matches)!=1: raise SystemExit(f'Missing/ambiguous method {signature}')
    m=matches[0]
    check=f"\n        if (apply_filters('sws_external_payment_owner', false, {var}, '{operation}')) {{ {returns} }}"
    changes[name]=text[:m.end()]+check+text[m.end():]
main='squarewoosync-pro.php'
if 'SWS_EXTERNAL_PAYMENT_OWNER_API' in read(main): raise SystemExit('Source already contains Cart Cat compatibility marker; verify instead of double-patching.')
replace(main,"if (!defined('ABSPATH')) {\n  exit; // Exit if accessed directly\n}","if (!defined('ABSPATH')) {\n  exit; // Exit if accessed directly\n}\n\n// External payment owners must implement the complete guarded lifecycle.\ndefine('SWS_EXTERNAL_PAYMENT_OWNER_API', 1);")
sync='includes/Woo/SyncOrder.php'
guard(sync,'public function create_order_in_background(int $order_id): void', 'wc_get_order($order_id)', 'order_sync')
guard(sync,"public function create_square_order_after_woo_order(int $order_id, string $old_status = '', string $new_status = '', $order = null): void", 'wc_get_order($order_id)', 'order_sync')
guard(sync,'public function create_square_order_after_checkout_order($order): void', '(is_object($order) ? $order : wc_get_order($order))', 'order_sync')
guard(sync,'public function add_square_sync_order_action(array $actions, $order): array', '$order','order_sync','return $actions;')
guard(sync,'public function handle_square_sync_order_action($order): void','(is_object($order) ? $order : wc_get_order($order))','order_sync')
job='includes/Jobs/OrderSyncJob.php'
replace(job,'$order = wc_get_order($this->order_id);',"$order = wc_get_order($this->order_id);\n            if (apply_filters('sws_external_payment_owner', false, $order, 'order_sync')) { delete_option($lock_key); return; }")
capture='includes/Payments/PaymentCaptureManager.php'
guard(capture,'public function charge_saved_card(\\WC_Order $order, string $card_id): array','$order','charge',"return ['success' => false, 'message' => 'Use the owning payment workflow for this order.'];")
guard(capture,'private function order_is_chargeable(\\WC_Order $order): bool','$order','charge','return false;')
gateway='includes/Payments/WC_SquareSync_Gateway.php'
guard(gateway,'public function process_payment($order_id)','wc_get_order($order_id)','charge',"return ['result' => 'failure'];")
guard(gateway,"public function process_refund($order_id, $amount = null, $reason = '')",'wc_get_order($order_id)','refund',"return new \\WP_Error('external_payment_owner', 'Use the owning payment gateway.');")
woo='includes/Orders/WooOrder.php'
guard(woo,'public static function handle_wc_refund_created($order_id, $refund_id)','wc_get_order($order_id)','refund')
guard(woo,'private static function maybe_refund_square_payment($wc_order, $square_helper)','$wc_order','refund')
create='includes/Woo/CreateOrder.php'
guard(create,"public function create_woocommerce_order($squareOrder, $email_settings = ['adminEmail' => true, 'customerEmail' => true])",'$squareOrder','import')
# Provider ownership is identified before canonical metadata can be saved (early webhook race).
text=read(create); text=text.replace("apply_filters('sws_external_payment_owner', false, $squareOrder, 'import')", "apply_filters('sws_external_square_order_owner', false, $squareOrder)"); changes[create]=text
guard(create,'private function set_order_status($order, $squareOrder, ?string $newOrderStatus = null)','$order','inbound_status')
webhook='includes/Jobs/WebhookProcessJob.php'
guard(webhook,'private function transition_order_from_square($order, $new_status, $note)','$order','inbound_status')
replace(webhook,'$wc_order = $this->find_wc_order_by_square_payment($payment_id, $order_id);',"$wc_order = $this->find_wc_order_by_square_payment($payment_id, $order_id);\n        if (apply_filters('sws_external_payment_owner', false, $wc_order, 'inbound_refund')) { return; }")
shutil.copytree(src,out)
manifest={}; patch=[]
for name,text in changes.items():
    original=(src/name).read_text(); (out/name).write_text(text)
    manifest[name]={'before':hashlib.sha256((src/name).read_bytes()).hexdigest(),'after':hashlib.sha256(text.encode()).hexdigest()}
    patch.extend(difflib.unified_diff(original.splitlines(True),text.splitlines(True),fromfile='a/'+name,tofile='b/'+name))
(out/'cart-cat-compat-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
if a.patch: pathlib.Path(a.patch).write_text(''.join(patch))
print(json.dumps({'output':str(out),'files':len(changes)},indent=2))
