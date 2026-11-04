<?php
namespace Bitsnap\WooCommerce\Tests\Unit;
use Bitsnap\WooCommerce\OAuth\Protocol;
use PHPUnit\Framework\TestCase;
final class OAuthProtocolTest extends TestCase {
 public function test_rfc_7636_vector_and_authorize_url(): void {
  $v='dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
  self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Protocol::challenge($v));
  $url=Protocol::authorize_url('https://bitsnap.pl','cid','https://shop/callback','state',Protocol::challenge($v),'test'); self::assertStringContainsString('scope=woocommerce%20offline_access',$url); self::assertStringContainsString('environment=test',$url); self::assertStringContainsString('resource=https%3A%2F%2Fbitsnap.pl%2Fapi%2Fv1',$url);
 }
 public function test_state_expiry_user_binding_and_reuse(): void {
  $r=array('state'=>'abc','user'=>4,'expires'=>100); self::assertTrue(Protocol::valid_state($r,'abc',4,99)); self::assertFalse(Protocol::valid_state($r,'abc',5,99)); self::assertFalse(Protocol::valid_state($r,'abc',4,101)); self::assertFalse(Protocol::valid_state(array(),'abc',4,99));
 }
 public function test_expiry_tokens_and_reregister_decision(): void {
  self::assertTrue(Protocol::should_refresh(159,100)); self::assertFalse(Protocol::should_refresh(161,100));
  $data=array('access_token'=>'a','refresh_token'=>'r','expires_in'=>60,'bitsnap_project_id'=>'p','bitsnap_project_name'=>'Shop','bitsnap_environment'=>'test'); self::assertSame('Shop',Protocol::parse_tokens($data)['project_name']); self::assertNull(Protocol::parse_tokens(array()));
  self::assertTrue(Protocol::should_reregister(401,'invalid_client')); self::assertFalse(Protocol::should_reregister(400,'invalid_client'));
 }
}
