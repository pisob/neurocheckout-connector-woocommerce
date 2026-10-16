<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(2); }
require __DIR__ . '/../neurocheckout-connector/includes/class-ncwoo-marketing-consent.php';
$checks = 0; $uid = 7; $blog = 2; $member = true; $multi = true; $write = true;
$users = [7 => (object) ['user_email' => 'Owner@example.invalid', 'first_name' => 'First', 'last_name' => 'Last']];
$metadata = []; $hooks = []; $notices = []; $writes = 0;
function checkConsent($ok, $label) { global $checks; ++$checks; if (!$ok) throw new RuntimeException($label); }
function get_current_user_id() { global $uid; return $uid; }
function get_current_blog_id() { global $blog; return $blog; }
function is_multisite() { global $multi; return $multi; }
function is_user_member_of_blog($user, $scope) { global $member; return $member; }
function get_userdata($user) { global $users; return $users[$user] ?? false; }
function get_user_meta($user, $key, $single) { global $metadata; return $metadata[$user][$key] ?? []; }
function update_user_meta($user, $key, $value) { global $metadata, $write, $writes; ++$writes; if ($write) $metadata[$user][$key] = [$value]; return $write; }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function wp_unslash($value) { return $value; }
function wp_verify_nonce($value, $action) { return $value === hash('sha256', 'test-only:' . $action); }
function wp_nonce_field($action, $name, $referer) { echo '<input name="'.$name.'" value="'.hash('sha256', 'test-only:' . $action).'">'; }
function __($text, $domain) { return $text; }
function esc_html__($text, $domain) { return htmlspecialchars($text, ENT_QUOTES); }
function wc_add_notice($text, $type) { global $notices; $notices[] = $text; }
function add_action($hook, $callback) { global $hooks; $hooks[$hook] = $callback; }
function consentState() { return NCWooMarketingConsent::snapshot(7, 2)['marketing_consent']['state']; }
function formConsent() {
    ob_start(); NCWooMarketingConsent::render(); $html = ob_get_clean();
    preg_match('/name="ncwoo_marketing_nonce" value="([^"]+)"/', $html, $match);
    return [$html, $match[1] ?? ''];
}
NCWooMarketingConsent::register();
checkConsent(count($hooks) === 2 && isset($hooks['woocommerce_save_account_details']), 'native account hooks');
checkConsent(consentState() === 'unknown', 'existing customers never opted in automatically');
[$html, $nonce] = formConsent();
checkConsent(strpos($html, ' checked') === false && $nonce !== '', 'optional unchecked control and nonce');
checkConsent(NCWooMarketingConsent::snapshot(7, 2)['customer']->email === 'owner@example.invalid', 'account identity, not billing');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['ncwoo_marketing_nonce' => $nonce, 'ncwoo_marketing_opt_in' => '1'];
NCWooMarketingConsent::save(7);
checkConsent(consentState() === 'granted', 'explicit authenticated choice granted');
checkConsent(strpos(formConsent()[0], ' checked') !== false, 'current choice displayed');
$record = $metadata[7]['_ncwoo_marketing_consent_2'][0];
checkConsent($record['customer_id'] === 7 && $record['blog_id'] === 2 && $record['version'] === 'product-recommendations-v1', 'scoped versioned record');
checkConsent(NCWooMarketingConsent::snapshot(7, 2)['marketing_consent']['changed_at'] !== null, 'real preference timestamp exported');
$before = $writes; NCWooMarketingConsent::save(7);
checkConsent($writes === $before, 'identical saves preserve original decision timestamp');
unset($_POST['ncwoo_marketing_opt_in']); NCWooMarketingConsent::save(7);
checkConsent(consentState() === 'denied', 'unchecking withdraws consent');
checkConsent(strpos(formConsent()[0], ' checked') === false, 'withdrawal visible');
foreach ([[], ['ncwoo_marketing_opt_in'=>'1'], ['ncwoo_marketing_nonce'=>'bad','ncwoo_marketing_opt_in'=>'1'],
    ['ncwoo_marketing_nonce'=>[$nonce],'ncwoo_marketing_opt_in'=>'1'],
    ['ncwoo_marketing_nonce'=>$nonce,'ncwoo_marketing_opt_in'=>['1']],
    ['ncwoo_marketing_nonce'=>$nonce,'ncwoo_marketing_opt_in'=>'true']] as $input) {
    $_POST = $input; $before = $writes; NCWooMarketingConsent::save(7);
    checkConsent($writes === $before && consentState() === 'denied', 'absent/malformed/unverified form cannot opt in');
}
$_POST = ['ncwoo_marketing_nonce' => $nonce, 'ncwoo_marketing_opt_in' => '1'];
foreach ([0, 8, '7', true] as $other) {
    $before = $writes; NCWooMarketingConsent::save($other);
    checkConsent($writes === $before, 'other or malformed account cannot change preference');
}
$_SERVER['REQUEST_METHOD'] = 'GET'; NCWooMarketingConsent::save(7);
checkConsent(consentState() === 'denied', 'GET cannot change preference');
$_SERVER['REQUEST_METHOD'] = 'POST'; NCWooMarketingConsent::save(7);
$users[7]->user_email = 'changed@example.invalid';
checkConsent(consentState() === 'unknown', 'grant never transfers to changed account address');
$before = $writes; NCWooMarketingConsent::save(7);
checkConsent($writes === $before, 'old form nonce cannot grant new email');
$_POST['ncwoo_marketing_nonce'] = formConsent()[1]; NCWooMarketingConsent::save(7);
checkConsent(consentState() === 'granted', 'new email can explicitly opt in on a fresh form');
$blog = 3;
checkConsent(NCWooMarketingConsent::snapshot(7, 3)['marketing_consent']['state'] === 'unknown', 'no cross-store preference inheritance');
checkConsent(NCWooMarketingConsent::snapshot(7, 2)['marketing_consent']['state'] === 'unknown', 'foreign scope lookup refused');
$blog = 2; $member = false;
checkConsent(consentState() === 'unknown', 'non-member of multisite store cannot inherit preference');
$member = true; $record = $metadata[7]['_ncwoo_marketing_consent_2'][0];
foreach ([['blog_id'=>3], ['customer_id'=>8], ['version'=>'unknown'], ['state'=>true], ['email'=>'foreign@example.invalid'],
    ['changed_at'=>time()+120], ['changed_at'=>0], ['changed_at'=>(string) time()]] as $change) {
    $metadata[7]['_ncwoo_marketing_consent_2'] = [array_replace($record, $change)];
    checkConsent(consentState() === 'unknown', 'malformed or foreign persisted evidence is not consent');
}
$metadata[7]['_ncwoo_marketing_consent_2'] = [$record, $record];
checkConsent(consentState() === 'unknown', 'duplicate metadata is not consent');
$metadata[7]['_ncwoo_marketing_consent_2'] = [$record];
$write = false; unset($_POST['ncwoo_marketing_opt_in']); NCWooMarketingConsent::save(7);
checkConsent(count($notices) === 1 && consentState() === 'granted', 'failed save shown honestly');
checkConsent(NCWooMarketingConsent::snapshot(0, 2)['marketing_consent']['state'] === 'unknown', 'guest has no account consent');
unset($users[7]); checkConsent(consentState() === 'unknown', 'deleted customer no longer eligible');
echo "Marketing consent: {$checks} isolated assertions passed.\n";
