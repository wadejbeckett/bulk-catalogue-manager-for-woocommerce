<?php
/** T83 native acceptance, never production. @package BulkCatalogueManager */
declare(strict_types=1);
use BulkCatalogueManager\Requests\RequestService;
if(!defined('WP_CLI')||!WP_CLI||'http://localhost:18925'!==get_option('siteurl')){exit(1);}
$checks=[];
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok){throw new RuntimeException($label);}$checks[]=$label;};
$check(method_exists(RequestService::class,'apply'),'Durable Apply service exists.');
wp_set_current_user(1);
$now=time();$service=new RequestService(static function()use(&$now):int{return $now;});
$ids=[];
try{
 $p=new WC_Product_Simple();$p->set_name('Exact batch probe');$p->set_status('publish');$p->set_regular_price('100');$ids[]=$p->save();
 $capture=$service->capture($service->issue('selection.capture',['ids'=>$ids]));
 $ops=[['action'=>'adjust','args'=>['field'=>'regular_price','direction'=>'increase','amount'=>'10','unit'=>'percent']]];
 $preview=$service->preview(['token'=>$capture['token'],'operations'=>$ops]);
 $check(is_array($preview),'Exact preview returns a scope.');
 $grant=$service->issue('batch.apply',['token'=>$capture['token'],'operations'=>$preview['operations'],'scope_hash'=>$preview['scope_hash']]);
 $check(is_array($grant),'Apply grant issued.');
 $result=$service->apply($grant+['token'=>$capture['token'],'preview_hash'=>$preview['hash']]);
 $check(is_array($result)&&$result['total_items']===1,'Apply persists exactly one item.');
 echo wp_json_encode(['checks'=>$checks,'batch'=>$result],JSON_PRETTY_PRINT)."\n";
}finally{foreach($ids as $id){wp_delete_post($id,true);}}
