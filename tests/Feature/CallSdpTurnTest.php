<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ChatCallController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CallSdpTurnTest extends TestCase
{
    public function test_sdp_keeps_final_crlf(): void
    {
        $sdp = "v=0\no=- 1 2 IN IP4 127.0.0.1\na=ssrc:1138580721 msid:435ffbab 817ce279";
        $this->assertSame("v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\na=ssrc:1138580721 msid:435ffbab 817ce279\r\n", ChatCallController::sdp($sdp));
    }

    public function test_cloudflare_turn_credentials(): void
    {
        Cache::flush();
        config(['flashpay.webrtc.cloudflare_key_id' => 'kid', 'flashpay.webrtc.cloudflare_token' => 'tok']);
        Http::fake(['rtc.live.cloudflare.com/*' => Http::response(['iceServers' => ['urls' => ['turn:turn.cloudflare.com:3478'], 'username' => 'u', 'credential' => 'c']], 201)]);
        $ice = ChatCallController::iceServers();
        $this->assertSame('u', collect($ice)->firstWhere('username', 'u')['username']);
        $this->assertFalse(collect($ice)->contains(fn ($s) => str_contains(json_encode($s), 'openrelay')));
    }
}
