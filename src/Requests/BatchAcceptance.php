<?php
/**
 * Prepare and persist batches under a durable receipt claim. Never enqueue here.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare(strict_types=1);
namespace BulkCatalogueManager\Requests;
use BulkCatalogueManager\Data\BatchRepository;
use BulkCatalogueManager\Engine\BatchFactory;
use BulkCatalogueManager\Engine\ExactScope;
use BulkCatalogueManager\Engine\Preview;
use BulkCatalogueManager\Rest\Refusal;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SnapshotService;
use BulkCatalogueManager\Selection\SnapshotToken;
use BulkCatalogueManager\Settings\Settings;
use BulkCatalogueManager\Undo\Planner;
defined('ABSPATH') || exit;

/** Only the already materialized, signature-checked scope reaches persistence. */
final class BatchAcceptance {
 /**
  * Called after the receipt's unique claim, in its owned transaction.
  *
  * @param array $claims Verified grant.
  * @param array $intent Immutable semantics.
  * @param array $credentials Preview and current selection credentials.
  * @param \Closure $clock Current time.
  * @return array|\WP_Error
  */
 public static function accept(array $claims,array $intent,array $credentials,\Closure $clock): array|\WP_Error {
  $batches=new BatchRepository();$factory=new BatchFactory();
  if([]!==$batches->request_candidates($claims['uuid'],$claims['owner'])){return self::legacy($claims['uuid'],$claims['owner']);}
  if('batch.undo'===$claims['operation']){
   $batches->lock($intent['batch_id']);
   $plan=(new Planner())->plan($intent['batch_id'],$claims['owner']);
   if(is_wp_error($plan)){return $plan;}
   if(!hash_equals($plan->hash(),$intent['plan_hash'])){return self::stale();}
   $scope=$factory->undo_scope($plan,$intent['force']);
   if(is_wp_error($scope)){return $scope;}
   [$original,$previous,$objects,$outline,$snapshot]=$scope;
   $result=$factory->record_scope($claims,array_values($outline),$objects,$snapshot,$original);
   if(!$batches->transition($original,$previous,'undoing')){return Refusal::create(Planner::NOT_REVERSIBLE,Planner::undoing_sentence(),409);}
   return $result;
  }
  $token=$credentials['token']??'';
  if(isset($intent['snapshot'])){
   $identity=SnapshotToken::identify(is_string($token)?$token:'',$claims['owner']);
   if(is_wp_error($identity)){return $identity;}
   if($identity['id']!==$intent['snapshot']){return SnapshotToken::invalid();}
   $selection=(new SnapshotService($clock))->selection($token,$claims['owner']);
   if(is_wp_error($selection)){return $selection;}
  }else{$selection=Selection::of_ids($intent['ids']);$token='';}
  $scope=ExactScope::build($selection,$intent['operations'],$factory);
  $hash=Preview::hash(''!==$token?$token:$selection->ids,$intent['operations'],Settings::load(),$scope['scope_hash']);
  if(!hash_equals($scope['scope_hash'],$intent['scope_hash'])||!is_string($credentials['preview_hash']??null)||!hash_equals($hash,$credentials['preview_hash'])){return self::stale();}
  if([]===$scope['objects']){return Refusal::invalid(BatchFactory::EMPTY,__('No products are selected. Choose products before making a change.','bulk-catalogue-manager-for-woocommerce'));}
  // Recheck lease after expansion/verification, under the same snapshot lock.
  if(isset($intent['snapshot'])){$valid=(new SnapshotService($clock))->selection($token,$claims['owner']);if(is_wp_error($valid)){return $valid;}}
  $snapshot=['kind'=>'snapshot','count'=>$selection->count,'selected'=>$selection->count,'materialised'=>count($scope['objects']),'scope_hash'=>$scope['scope_hash'],'variations_added'=>$scope['added'],'parents_left_out'=>$scope['left_out'],'unavailable'=>$scope['unavailable']];
  if(isset($intent['snapshot'])){$snapshot['snapshot_id']=$intent['snapshot'];}else{$snapshot['kind']='ids';$snapshot['ids']=$selection->ids;}
  return $factory->record_scope($claims,$intent['operations'],$scope['objects'],$snapshot);
 }
 /** Preview freshness refusal shared by both batch operations. */
 public static function stale(): \WP_Error {
  return Refusal::create(BatchFactory::STALE_PREVIEW,__('This change no longer matches its preview. Preview the exact selected products again, then apply it.','bulk-catalogue-manager-for-woocommerce'),409);
 }
 /**
  * Old screens cannot prove semantic equivalence. Never guess or create again.
  *
  * @param string $uuid Old request.
  * @param int $owner Current owner.
  */
 public static function legacy(string $uuid,int $owner): \WP_Error {
  $ids=(new BatchRepository())->request_candidates($uuid,$owner);
  return Refusal::create('bulkcat_legacy_request',__('This request came from an older screen. Check its existing change in History, then reload before starting new work.','bulk-catalogue-manager-for-woocommerce'),409,['batch_ids'=>$ids,'history'=>'history']);
 }
}
