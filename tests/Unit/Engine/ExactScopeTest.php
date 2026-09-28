<?php
/** Exact scope retains unavailable identities and binds reach, not just counts. @package BulkCatalogueManager */
declare(strict_types=1);
namespace BulkCatalogueManager\Tests\Unit\Engine;
use BulkCatalogueManager\Engine\ExactScope;
use BulkCatalogueManager\Selection\Selection;
use PHPUnit\Framework\TestCase;
final class ExactScopeTest extends TestCase {
 public function test_typed_selection_keeps_sorted_missing_members(): void {
  $selection=Selection::of_members(['products'=>[9,2],'variations'=>[5]]);
  $this->assertSame([[2,'product'],[5,'variation'],[9,'product']],$selection->members);
  $this->assertSame([2,5,9],$selection->ids);
  $this->assertSame(0,Selection::of_members(['products'=>[],'variations'=>[]])->count);
 }
 public function test_scope_signature_covers_identity_type_reach_and_unavailable(): void {
  bulkcat_test_reset();
  $ops=[['action'=>'set','args'=>['field'=>'regular_price','value'=>'12']]];
  $a=ExactScope::signature([[1,'product'],[2,'variation']],$ops,[]);
  $this->assertSame($a,ExactScope::signature([[1,'product'],[2,'variation']],$ops,[]));
  foreach([[[1,'product'],[3,'variation']],[[1,'variation'],[2,'variation']],[[1,'product',[]],[2,'variation']]] as $objects){
   $this->assertNotSame($a,ExactScope::signature($objects,$ops,[]));
  }
  $this->assertNotSame($a,ExactScope::signature([[1,'product'],[2,'variation']],$ops,[2]));
 }
}
