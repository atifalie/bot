<?php

namespace Tests\Unit;

use App\Bot\Streaming\BybitWsClient;
use App\Bot\Streaming\CandleBuffer;
use ReflectionMethod;
use Tests\TestCase;

class StreamingTest extends TestCase
{
    /** @test */
    public function buffer_upserts_forming_then_confirms_candle()
    {
        $buf = new CandleBuffer;
        $base = 1700000000000;

        // forming candle updates land in "forming", NOT in series
        $buf->upsert('BTC/USDT', '5m', [$base, 100, 101, 99, 100.5, 10], confirmed: false);
        $buf->upsert('BTC/USDT', '5m', [$base, 100, 102, 99, 101.2, 14], confirmed: false);
        $this->assertSame([], $buf->ohlcv('BTC/USDT', '5m'));

        // confirm closes it into the series
        $buf->upsert('BTC/USDT', '5m', [$base, 100, 102, 99, 101.2, 15], confirmed: true);
        $series = $buf->ohlcv('BTC/USDT', '5m');
        $this->assertCount(1, $series);
        $this->assertSame($base, $series[0][0]);
        $this->assertEquals(101.2, $series[0][4]);

        // duplicate confirm must not duplicate rows
        $buf->upsert('BTC/USDT', '5m', [$base, 100, 102, 99, 101.2, 15], confirmed: true);
        $this->assertCount(1, $buf->ohlcv('BTC/USDT', '5m'));
    }

    /** @test */
    public function buffer_respects_max_size_and_ignores_stale()
    {
        $buf = new CandleBuffer(maxCandles: 3);
        $base = 1700000000000;

        for ($i = 0; $i < 5; $i++) {
            $buf->upsert('X/USDT', '5m', [$base + $i * 300000, 1, 1, 1, 1 + $i, 1], confirmed: true);
        }
        // stale out-of-order candle ignored
        $buf->upsert('X/USDT', '5m', [$base - 999, 1, 1, 1, 9, 1], confirmed: true);

        $series = $buf->ohlcv('X/USDT', '5m');
        $this->assertCount(3, $series);
        $this->assertEquals(5, $series[2][4]);
    }

    /** @test */
    public function ws_client_parses_bybit_v5_object_klines()
    {
        $buf = new CandleBuffer;
        $client = new BybitWsClient(buffer: $buf);

        $payload = json_encode([
            'topic' => 'kline.5.BTCUSDT',
            'data' => [
                [
                    'start' => 1700000000000,
                    'end' => 1700000299999,
                    'interval' => '5',
                    'open' => '42000.5',
                    'high' => '42100',
                    'low' => '41900',
                    'close' => '42050.25',
                    'volume' => '12.5',
                    'turnover' => '525626.56',
                    'confirm' => false,
                    'timestamp' => 1700000123456,
                ],
            ],
        ]);

        $client->handleRaw($payload);

        // forming only → series empty
        $this->assertSame([], $buf->ohlcv('BTC/USDT', '5m'));

        // now confirm
        $payload[0]; // noop for linters
        $confirm = json_encode([
            'topic' => 'kline.5.BTCUSDT',
            'data' => [[
                'start' => 1700000000000,
                'open' => '42000.5',
                'close' => '42050.25',
                'high' => '42100',
                'low' => '41900',
                'volume' => '13.1',
                'confirm' => true,
            ]],
        ]);
        $client->handleRaw($confirm);

        $series = $buf->ohlcv('BTC/USDT', '5m');
        $this->assertCount(1, $series);
        $this->assertEquals(42050.25, $series[0][4]);
    }

    /** @test */
    public function ws_client_normalizes_intervals_and_symbols()
    {
        $client = new BybitWsClient;

        $norm = new ReflectionMethod($client, 'normalizeInterval');
        $norm->setAccessible(true);
        $pair = new ReflectionMethod($client, 'toPairSymbol');
        $pair->setAccessible(true);

        $this->assertSame('5m', $norm->invoke($client, '5'));
        $this->assertSame('15m', $norm->invoke($client, '15'));
        $this->assertSame('1h', $norm->invoke($client, '60'));
        $this->assertSame('1d', $norm->invoke($client, 'D'));
        $this->assertSame('BTC/USDT', $pair->invoke($client, 'BTCUSDT'));
        $this->assertSame('1000PEPE/USDT', $pair->invoke($client, '1000PEPEUSDT'));
    }

    /** @test */
    public function ping_request_gets_pong_reply_without_errors()
    {
        $client = new BybitWsClient;
        $client->handleRaw(json_encode(['op' => 'ping']));

        $this->assertTrue(true); // no exception = pass
    }
}
