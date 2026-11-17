<?php

namespace Tests\Feature\Instagram;

use App\Models\InstagramReplyRule;
use App\Services\Instagram\ReplyRuleMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplyRuleMatcherTest extends TestCase
{
    use RefreshDatabase;

    private function rule(array $attrs): InstagramReplyRule
    {
        return InstagramReplyRule::create($attrs + ['keywords' => ['قیمت'], 'private_text' => 'x', 'enabled' => true, 'position' => 0]);
    }

    public function test_arabic_yeh_matches_persian_keyword()
    {
        $rule = $this->rule([]);

        $this->assertTrue($rule->is(app(ReplyRuleMatcher::class)->match('قيمت چنده؟', 'm1')));
    }

    public function test_rule_for_other_media_is_skipped()
    {
        $this->rule(['media_id' => 'A']);

        $this->assertNull(app(ReplyRuleMatcher::class)->match('قیمت', 'B'));
        $this->assertNotNull(app(ReplyRuleMatcher::class)->match('قیمت', 'A'));
    }

    public function test_lowest_position_wins()
    {
        $this->rule(['position' => 2, 'private_text' => 'second']);
        $first = $this->rule(['position' => 1, 'private_text' => 'first']);

        $this->assertTrue($first->is(app(ReplyRuleMatcher::class)->match('قیمت؟', null)));
    }

    public function test_disabled_rule_and_non_matching_text()
    {
        $this->rule(['enabled' => false]);

        $this->assertNull(app(ReplyRuleMatcher::class)->match('قیمت', null));
        $this->rule(['keywords' => ['price']]);
        $this->assertNull(app(ReplyRuleMatcher::class)->match('سلام', null));
        $this->assertNotNull(app(ReplyRuleMatcher::class)->match('PRICE?', null));
    }
}
