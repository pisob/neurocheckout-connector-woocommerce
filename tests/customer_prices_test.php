<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
use NeuroCheckout\WooCommerce\Community\WooCustomerProductPrices;
require_once __DIR__.'/../neurocheckout-connector/includes/community/WooCustomerProductPrices.php';
set_error_handler(static function ($severity, $message) { throw new RuntimeException($message); });
define('WC_VERSION', '10.7.0');
class TestNumberUtil {static function round($value,$dp){return round(round($value,6),$dp);}}
class_alias(TestNumberUtil::class, 'Automattic\\WooCommerce\\Utilities\\NumberUtil');
$checks=0; $scope=1; $wp_filter=[]; $products=[]; $held=0; $clockValue=1000.0;
$options=['woocommerce_tax_display_shop'=>'incl','woocommerce_tax_based_on'=>'shipping','woocommerce_tax_round_at_subtotal'=>'no'];
$currency='EUR'; $decimals=2; $enabled=true; $included=false; $exempt=false;
$user=(object)['ID'=>47,'user_status'=>0,'user_email'=>'recipient@example.invalid'];
$location=['FR','','75001','Paris']; $base=[1=>['rate'=>20]]; $rates=[2=>['rate'=>10]];
$blogSequence=[]; $currencySequence=[]; $delay=false; $multi=false; $member=true;
$site=(object)['archived'=>0,'spam'=>0,'deleted'=>0]; $password='';
function get_current_blog_id() { global $scope,$blogSequence; return $blogSequence?array_shift($blogSequence):$scope; }
function did_action($action) { return 1; }
function get_user_by($field,$id) { return $GLOBALS['user']; }
function is_multisite() { return $GLOBALS['multi']; }
function is_user_member_of_blog($id,$blog) { return $GLOBALS['member']; }
function get_site($id) { return $GLOBALS['site']; }
function get_woocommerce_currency() { global $currencySequence,$currency; return $currencySequence?array_shift($currencySequence):$currency; }
function wc_get_price_decimals() { return $GLOBALS['decimals']; }
function get_option($key) { return $GLOBALS['options'][$key]??null; }
function wc_tax_enabled() { return $GLOBALS['enabled']; }
function wc_prices_include_tax() { return $GLOBALS['included']; }
function wc_format_decimal($value,$dp) { return number_format((float)$value,$dp,'.',''); }
function wc_round_tax_total($value) { return round($value,wc_get_price_decimals()); }
function wc_get_product($id) { if($GLOBALS['delay'])$GLOBALS['clockValue']+=4; return $GLOBALS['products'][$id]??false; }
function wc_get_held_stock_quantity($p) { return $GLOBALS['held']; }
function get_post_field($field,$id) { return $GLOBALS['password']; }
class TestCountries {
    function get_countries(){return ['FR'=>'France','US'=>'United States','HK'=>'Hong Kong'];}
    function get_states($country){return $country==='US'?['CA'=>'California']:[];}
    function get_address_fields($country,$prefix){return ['billing_postcode'=>['required'=>$country!=='HK'],'billing_city'=>['required'=>true]];}
    function get_base_country(){return 'FR';} function get_base_state(){return '';}
    function get_base_postcode(){return '75001';} function get_base_city(){return 'Paris';}
}
$woo=(object)['countries'=>new TestCountries(),'customer'=>(object)['sentinel'=>'unrelated session'],'session'=>(object)['sentinel'=>'unchanged']];
function WC(){return $GLOBALS['woo'];}
class TestStore {private $name; function __construct($name){$this->name=$name;} function get_current_class_name(){return $this->name;}}
class WC_Customer {
    private $id; function __construct($id,$session){if($session)throw new RuntimeException('session forbidden');$this->id=$id;}
    function get_id(){return $this->id;} function get_email(){return $GLOBALS['user']->user_email;}
    function get_data_store(){return new TestStore('WC_Customer_Data_Store');}
    function get_is_vat_exempt(){return $GLOBALS['exempt'];}
    function __call($name,$args){$fields=['country'=>0,'state'=>1,'postcode'=>2,'city'=>3];return $GLOBALS['location'][$fields[substr($name,strrpos($name,'_')+1)]];}
}
class WC_Product_Simple {
    public $id=13,$status='publish',$price='30',$regular='30',$sale='',$onSale=false,$taxable=true,$stock=5,$managed=true,$visibility='visible',$canBuy=true,$inStock=true,$stockStatus='instock',$store='WC_Product_Data_Store_CPT';
    function get_id(){return $this->id;} function get_status(){return $this->status;}
    function is_purchasable(){return $this->canBuy;} function is_in_stock(){return $this->inStock;}
    function has_enough_stock($qty){return $this->stock>=$qty;} function get_stock_status(){return $this->stockStatus;}
    function get_data_store(){return new TestStore($this->store);} function get_price(){return $this->price;}
    function get_regular_price(){return $this->regular;} function get_sale_price(){return $this->sale;}
    function is_on_sale(){return $this->onSale;} function is_taxable(){return $this->taxable;}
    function get_tax_class($context='view'){return '';}
    function get_catalog_visibility(){return $this->visibility;} function managing_stock(){return $this->managed;}
    function get_stock_quantity(){return $this->stock;} function get_stock_managed_by_id(){return $this->id;}
}
class WC_Product_Variable extends WC_Product_Simple {public $attrs=['pa_size'=>['small','large']]; function get_variation_attributes(){return $this->attrs;}}
class WC_Product_Variation extends WC_Product_Simple {
    public $parent=20,$active=true,$visible=true,$attrs=['pa_size'=>'small'];
    function get_parent_id(){return $this->parent;} function variation_is_active(){return $this->active;}
    function variation_is_visible(){return $this->visible;} function get_attributes(){return $this->attrs;}
}
class WC_Tax {
    public static $seen=[];
    static function get_rates_from_location($class,$location,$customer){self::$seen=[$location,$customer->get_id()];return $GLOBALS['rates'];}
    static function get_base_tax_rates($class){return $GLOBALS['base'];}
    static function calc_tax($price,$rates,$included){$sum=array_sum(array_column($rates,'rate'));$out=[];foreach($rates as $id=>$rate)$out[$id]=$price*$rate['rate']/($included?100+$sum:100);return $out;}
}
class WC_Deprecated_Filter_Hooks {function maybe_handle_deprecated_hook(){}}
$products[13]=new WC_Product_Simple();
$reader=new WooCustomerProductPrices(static function(){return $GLOBALS['clockValue'];});
function checkPrice($ok,$label){global $checks;++$checks;if(!$ok)throw new RuntimeException($label);}
function readPrice($args=[]){global $reader;return $reader->read(...array_replace([1,47,'recipient@example.invalid',['13'],'EUR'],$args));}
function rejectPrice(callable $call,$label){try{$call();}catch(Throwable $e){checkPrice($e->getMessage()==='customer_prices_unavailable',$label.' redacted');return;}throw new RuntimeException($label.' accepted');}
function availablePrice($expected,$label){$r=readPrice();checkPrice($r['offers'][0]['status']==='available' && abs($r['offers'][0]['display_price']-$expected)<1e-9,$label);return $r;}
function unavailablePrice($label){$r=readPrice();checkPrice($r['offers'][0]===['product_id'=>'13','status'=>'unavailable'],$label);}
$savedCustomer=WC()->customer; $savedSession=WC()->session;
$r=availablePrice(33,'recipient tax not anonymous base');
checkPrice(WC_Tax::$seen===[$location,47],'tax services receive explicit recipient and location');
checkPrice($r['quantity']===1 && $r['currency']==='EUR' && $r['blog_id']===1 && $r['customer_id']===47,'scoped offer');
checkPrice(strlen($r['context_hash'])===64 && !isset($r['address']),'no exported address');
$options['woocommerce_tax_display_shop']='excl';availablePrice(30,'exclusive display');
$options['woocommerce_tax_display_shop']='incl'; $included=true;
$products[13]->price=$products[13]->regular='120';availablePrice(110,'remove base 20 then apply recipient 10');
$rates=$base;availablePrice(120,'same-rate inclusive price unchanged');
$exempt=true;availablePrice(100,'VAT exempt removes included base tax');
$included=false;availablePrice(120,'VAT exempt excludes added tax');
$exempt=false;$rates=[];availablePrice(120,'verified location with no matched rates is zero tax');
$enabled=false;$location=['','','',''];availablePrice(120,'tax disabled does not require an address');
$enabled=true;rejectPrice(static function(){readPrice();},'missing taxable address');$location=['FR','','75001','Paris'];
$rates=[2=>['rate'=>10]];
foreach([['FR','','','Paris'],['XX','','75001','Paris'],['US','ZZ','94000','City'],['FR','','75001','']] as $bad){$location=$bad;rejectPrice(static function(){readPrice();},'incomplete/invalid location');}
$location=['HK','','','Hong Kong'];availablePrice(132,'native postcode-optional country');$location=['FR','','75001','Paris'];
$options['woocommerce_tax_based_on']='base';availablePrice(132,'native base address');checkPrice(WC_Tax::$seen[0]===['FR','','75001','Paris'],'explicit native base location');
$options['woocommerce_tax_based_on']='billing';availablePrice(132,'native billing address');$options['woocommerce_tax_based_on']='shipping';
foreach(['yes','no'] as $round){$options['woocommerce_tax_round_at_subtotal']=$round;foreach([0,2,3] as $dp){$decimals=$dp;$products[13]->price=$products[13]->regular='10.1234';$tax=10.1234*.1;$expected=round(10.1234+($round==='yes'?$tax:round($tax,$dp)),$dp);availablePrice($expected,'native rounding '.$round.' decimals '.$dp);}}
$options['woocommerce_tax_round_at_subtotal']='no';$decimals=2;$products[13]->price=$products[13]->regular='30';
$products[13]->onSale=true;$products[13]->sale=$products[13]->price='20';availablePrice(22,'active native sale');
$products[13]->onSale=false;unavailablePrice('expired sale price not advertised');$products[13]->price='30';
$products[13]->onSale=true;unavailablePrice('not applied sale not silently invented');$products[13]->onSale=false;
foreach([['status','draft'],['visibility','hidden'],['visibility','search'],['canBuy',false],['inStock',false],['stock',0],['stockStatus','onbackorder'],['store','CustomStore'],['price',''],['price','NaN'],['price','-1'],['price','1000000001']] as [$key,$value]){
 $old=$products[13]->$key;$products[13]->$key=$value;unavailablePrice('product exclusion '.$key);$products[13]->$key=$old;
}
$held=5;unavailablePrice('held stock exhausted');$held=0;$password='protected';unavailablePrice('password product excluded');$password='';
$saved=$products[13];unset($products[13]);unavailablePrice('missing product');$products[13]=$saved;
$parent=new WC_Product_Variable();$parent->id=20;$products[20]=$parent;
$variant=new WC_Product_Variation();$variant->store='WC_Product_Variation_Data_Store_CPT';$products[13]=$variant;
availablePrice(33,'explicit purchasable variation');$variant->attrs=['pa_size'=>''];unavailablePrice('wildcard variation excluded');
$variant->attrs=['pa_size'=>'unknown'];unavailablePrice('invalid variation option');$variant->attrs=['pa_size'=>'small'];
$parent->status='draft';unavailablePrice('unpublished parent');$parent->status='publish';$parent->visibility='hidden';unavailablePrice('hidden parent');$parent->visibility='visible';
$variant->active=false;unavailablePrice('disabled variation');$variant->active=true;$products[13]=$saved;
foreach([[0=>2],[1=>0],[2=>'wrong@example.invalid'],[2=>'bad'],[3=>[]],[3=>['13','13']],[3=>[13]],[3=>['0']],[3=>['01']],[3=>array_map('strval',range(1,17))],[4=>'USD']] as $args){rejectPrice(static function()use($args){readPrice($args);},'request boundary');}
$currencySequence=['EUR','USD'];rejectPrice(static function(){readPrice();},'currency drift');$currencySequence=[];
$blogSequence=[1,2];rejectPrice(static function(){readPrice();},'site drift');$blogSequence=[];
$delay=true;rejectPrice(static function(){readPrice();},'whole batch timeout');$delay=false;
$user->user_status=1;rejectPrice(static function(){readPrice();},'disabled account');$user->user_status=0;
$multi=true;$member=false;rejectPrice(static function(){readPrice();},'foreign site membership');$member=true;$multi=false;
$inert=(object)['callbacks'=>[[['function'=>[new WC_Deprecated_Filter_Hooks(),'maybe_handle_deprecated_hook']]]]];
$wp_filter=['woocommerce_product_get_price'=>$inert];availablePrice(33,'inert native deprecation bridge');
require_once __DIR__.'/fixtures/native-tax-observer.php';
$taxTask=new \Automattic\WooCommerce\Admin\Features\OnboardingTasks\Tasks\Tax();
$wp_filter=[];
foreach (['added','deleted'] as $event) {
 $wp_filter['woocommerce_tax_rate_'.$event]=(object)['callbacks'=>[[['function'=>[$taxTask,'on_tax_rate_'.$event]]]]];
}
availablePrice(33,'REST native tax onboarding observers do not affect one-unit prices or run');
$wp_filter['woocommerce_product_get_price']=(object)['callbacks'=>[[['function'=>static function(){}]]]];
rejectPrice(static function(){readPrice();},'REST observers never allow a custom price callback');
$wp_filter=['woocommerce_tax_rate_added'=>(object)['callbacks'=>[[['function'=>static function(){}]]]]];
rejectPrice(static function(){readPrice();},'unqualified tax observer remains blocked');
$wp_filter=['woocommerce_tax_rate_deleted'=>(object)['callbacks'=>[[['function'=>[$taxTask,'on_tax_rate_added']]]]]];
rejectPrice(static function(){readPrice();},'mismatched tax observer hook remains blocked');

foreach(['woocommerce_get_price','woocommerce_product_get_price','woocommerce_customer_loaded','woocommerce_get_price_including_tax','woocommerce_adjust_non_base_location_prices','woocommerce_currency','woocommerce_product_data_store','woocommerce_before_calculate_totals','pre_option','get_user_metadata'] as $hook){
 $wp_filter=[$hook=>(object)['callbacks'=>[[['function'=>static function(){}]]]]];rejectPrice(static function(){readPrice();},'unknown runtime hook '.$hook);
}
$wp_filter=[];
checkPrice(WC()->customer===$savedCustomer && WC()->session===$savedSession,'session and authenticated customer never replaced');
echo "Woo recipient-price reader: $checks checks passed.\n";
