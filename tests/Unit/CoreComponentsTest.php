<?php

namespace Tests\Unit;

use App\Bot\Features\FeatureSet;
use App\Bot\Features\Indicators;
use App\Bot\MarketData\CandleSeries;
use App\Bot\Risk\CircuitBreaker;
use App\Bot\Risk\RiskAssessor;
use App\Bot\Scoring\ScoreCalibration;
use App\Bot\Scoring\ScoringEngine;
use App\Bot\Strategy\MarketRegime;
use App\Bot\Strategy\RegimeDetector;
use App\Bot\Strategy\SessionFilter;
use App\Bot\Strategy\TrailingStop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoreComponentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /** @test */
    public function indicators_compute_all_returns_featureset()
    {
        $raw = [];
        $price = 100.0;
        for ($i = 0; $i < 200; $i++) {
            $open = $price;
            $close = $price + (mt_rand(-100, 100) / 100) * 0.6;
            $high = max($open, $close) + (mt_rand(0, 100) / 100) * 0.4;
            $low = min($open, $close) - (mt_rand(0, 100) / 100) * 0.4;
            $volume = 1000 + mt_rand(0, 500);
            $ts = 1700000000000 + $i * 900000;
            $raw[] = [$ts, $open, $high, $low, $close, $volume];
            $price = $close;
        }

        $series = CandleSeries::fromRaw($raw);
        $cfg = [
            'fast_ma_period' => 9, 'slow_ma_period' => 21, 'rsi_period' => 14,
            'atr_period' => 14, 'volume_ma_period' => 20,
            'macd_fast' => 12, 'macd_slow' => 26, 'macd_signal' => 9,
            'bb_period' => 20, 'bb_std_dev' => 2.0, 'stoch_rsi_period' => 14,
        ];

        $features = Indicators::computeAll($series, $cfg);

        $this->assertInstanceOf(FeatureSet::class, $features);
        $this->assertEquals(200, $features->length());
        $this->assertTrue($features->has('rsi'));
        $this->assertTrue($features->has('atr'));
        $this->assertTrue($features->has('macd_histogram'));
        $this->assertTrue($features->has('bb_pct_b'));
        $this->assertTrue($features->has('adx'));
    }

    /** @test */
    public function regime_detector_returns_market_regime()
    {
        $raw = [];
        $price = 100.0;
        for ($i = 0; $i < 200; $i++) {
            $open = $price;
            $close = $price + (mt_rand(-100, 100) / 100) * 0.6;
            $high = max($open, $close) + (mt_rand(0, 100) / 100) * 0.4;
            $low = min($open, $close) - (mt_rand(0, 100) / 100) * 0.4;
            $volume = 1000 + mt_rand(0, 500);
            $ts = 1700000000000 + $i * 900000;
            $raw[] = [$ts, $open, $high, $low, $close, $volume];
            $price = $close;
        }

        $series = CandleSeries::fromRaw($raw);
        $cfg = ['fast_ma_period' => 9, 'slow_ma_period' => 21, 'rsi_period' => 14, 'atr_period' => 14, 'volume_ma_period' => 20, 'macd_fast' => 12, 'macd_slow' => 26, 'macd_signal' => 9, 'bb_period' => 20, 'bb_std_dev' => 2.0, 'stoch_rsi_period' => 14];
        $features = Indicators::computeAll($series, $cfg);

        $detector = new RegimeDetector;
        $regime = $detector->detect($features);

        $this->assertInstanceOf(MarketRegime::class, $regime);
        $this->assertContains($regime->regime, ['TRENDING', 'RANGING', 'TRANSITIONAL', 'UNKNOWN']);
        $this->assertIsFloat($regime->adx);
        $this->assertIsBool($regime->tradeable);
    }

    /** @test */
    public function session_filter_detects_current_session()
    {
        $session = SessionFilter::getCurrentSession();
        $this->assertArrayHasKey('session', $session);
        $this->assertArrayHasKey('quality', $session);
        $this->assertArrayHasKey('tradeable', $session);
        $this->assertArrayHasKey('reason', $session);
    }

    /** @test */
    public function trailing_stop_updates_correctly()
    {
        // Below min profit lock — keep original SL
        $sl = TrailingStop::update(100.0, 101.0, 98.0, 0.5, 1.5, 2.5);
        $this->assertEquals(98.0, $sl);

        // Above min profit lock — breakeven lock then trail
        // profit = 3% >= 2.5% → breakeven (100), then trail: 103 - 0.5*1.5 = 102.25
        $sl = TrailingStop::update(100.0, 103.0, 98.0, 0.5, 1.5, 2.5);
        $this->assertEquals(102.25, $sl);

        // Trailing active
        $sl = TrailingStop::update(100.0, 110.0, 98.0, 1.0, 1.5, 2.5);
        $this->assertGreaterThan(100.0, $sl);
    }

    /** @test */
    public function circuit_breaker_blocks_on_daily_loss()
    {
        $breaker = CircuitBreaker::fromConfig();
        $breaker->recordTrade(false, -3.0);
        $breaker->recordTrade(false, -3.0); // -6% total, max 5%
        [$allowed, $reason] = $breaker->isTradingAllowed();
        $this->assertFalse($allowed);
        $this->assertStringContainsString('daily_loss_limit_hit', $reason);
    }

    /** @test */
    public function circuit_breaker_blocks_on_consecutive_losses()
    {
        $breaker = CircuitBreaker::fromConfig();
        for ($i = 0; $i < 4; $i++) {
            $breaker->recordTrade(false, -1.0);
        }
        [$allowed, $reason] = $breaker->isTradingAllowed();
        $this->assertFalse($allowed);
        $this->assertStringContainsString('cooldown_active', $reason);
    }

    /** @test */
    public function risk_assessor_approves_valid_trade()
    {
        $breaker = CircuitBreaker::fromConfig();
        $assessor = new RiskAssessor($breaker);
        $ra = $assessor->assess(100.0, 0.5, 1000.0);

        $this->assertTrue($ra->approved);
        $this->assertGreaterThan(0, $ra->positionSizeQuantity);
        $this->assertGreaterThan(0, $ra->stopLossPrice);
        $this->assertGreaterThan(0, $ra->takeProfitPrice);
        $this->assertGreaterThan(1.0, $ra->riskRewardRatio);
    }

    /** @test */
    public function risk_assessor_rejects_low_rr()
    {
        $breaker = CircuitBreaker::fromConfig();
        $assessor = new RiskAssessor($breaker);
        // TP mult=1.0, SL mult=2.0 -> R:R = 0.5 < 1.5
        $ra = $assessor->assess(100.0, 0.1, 1000.0, [
            'min_risk_reward_ratio' => 1.5,
            'atr_stop_loss_multiplier' => 2.0,
            'atr_take_profit_multiplier' => 1.0,
        ]);

        $this->assertFalse($ra->approved);
        $this->assertStringContainsString('rr_too_low', $ra->rejectionReason);
    }

    /** @test */
    public function scoring_engine_returns_result()
    {
        $raw = [];
        $price = 100.0;
        for ($i = 0; $i < 200; $i++) {
            $open = $price;
            $close = $price + (mt_rand(-100, 100) / 100) * 0.6;
            $high = max($open, $close) + (mt_rand(0, 100) / 100) * 0.4;
            $low = min($open, $close) - (mt_rand(0, 100) / 100) * 0.4;
            $volume = 1000 + mt_rand(0, 500);
            $ts = 1700000000000 + $i * 900000;
            $raw[] = [$ts, $open, $high, $low, $close, $volume];
            $price = $close;
        }

        $series = CandleSeries::fromRaw($raw);
        $cfg = ['fast_ma_period' => 9, 'slow_ma_period' => 21, 'rsi_period' => 14, 'atr_period' => 14, 'volume_ma_period' => 20, 'macd_fast' => 12, 'macd_slow' => 26, 'macd_signal' => 9, 'bb_period' => 20, 'bb_std_dev' => 2.0, 'stoch_rsi_period' => 14];
        $features = Indicators::computeAll($series, $cfg);
        $calib = new ScoreCalibration(...array_values(config('bot.calibration')));
        $engine = new ScoringEngine($calib);

        $result = $engine->computeScore($features, $series);

        $this->assertNotNull($result);
        $this->assertContains($result->direction, ['BUY', 'NEUTRAL']);
        $this->assertIsFloat($result->finalConfidence);
        $this->assertContains($result->band, ['EXCEPTIONAL', 'STRONG', 'ACCEPTABLE', 'WEAK', 'REJECT']);
        $this->assertCount(12, $result->factors);
    }
}
