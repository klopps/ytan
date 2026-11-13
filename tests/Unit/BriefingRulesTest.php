<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ytan\Service\Ai\BriefingRules;
use Ytan\Service\Ai\RouteGeometry;

final class BriefingRulesTest extends TestCase
{
    public function testDefaultsAreAValidRuleSet(): void
    {
        $this->assertEquals(BriefingRules::defaults(), BriefingRules::normalize(null));
        $this->assertEquals(BriefingRules::defaults(), BriefingRules::normalize([]));
    }

    public function testValuesAreClampedAndYellowStaysBelowRed(): void
    {
        $rules = BriefingRules::normalize([
            'wind' => ['yellow' => 9, 'red' => 7],
            'gust' => ['yellow' => 'x', 'red' => 99],
            'lee_factor' => 5,
            'levels' => ['beginner' => 0.1],
            'window_from' => 18,
            'window_to' => 7,
        ]);

        $this->assertEquals(['yellow' => 5.6, 'red' => 7.0], $rules['wind']);
        $this->assertEquals(['yellow' => 8.0, 'red' => 35.0], $rules['gust']);
        $this->assertEquals(2.0, $rules['lee_factor']);
        $this->assertEquals(0.3, $rules['levels']['beginner']);
        $this->assertEquals(1.0, $rules['levels']['experienced']);
        $this->assertSame(18, $rules['window_to']);
    }

    public function testLevelsAndHash(): void
    {
        $this->assertTrue(BriefingRules::isLevel('beginner'));
        $this->assertFalse(BriefingRules::isLevel('pro'));
        $this->assertFalse(BriefingRules::isLevel(null));
        $this->assertSame(BriefingRules::hash([]), BriefingRules::hash(BriefingRules::defaults()));
        $this->assertNotSame(BriefingRules::hash([]), BriefingRules::hash(['wind' => ['yellow' => 4, 'red' => 8]]));
    }

    public function testRouteGeometry(): void
    {
        $route = new RouteGeometry([[56.0, 10.0], [56.0, 10.1], [56.05, 10.1]]);
        // 0.1 degrees of longitude at 56 N ~ 6.2 km, 0.05 degrees of latitude ~ 5.56 km
        $this->assertEqualsWithDelta(6218 + 5560, $route->length, 30);
        $this->assertEqualsWithDelta(90.0, RouteGeometry::bearing([56.0, 10.0], [56.0, 10.1]), 0.1);
        $this->assertEqualsWithDelta(0.0, RouteGeometry::bearing([56.0, 10.1], [56.05, 10.1]), 0.1);
        $this->assertSame(170.0, RouteGeometry::angleBetween(350.0, 180.0));

        $sections = $route->sections();
        $this->assertCount(4, $sections);
        $this->assertEqualsWithDelta($route->length, array_sum(array_column($sections, 'length')), 0.01);
        $this->assertSame($sections[0]['points'][0], [56.0, 10.0]);

        $near = $route->nearest(56.009, 10.05);
        $this->assertEqualsWithDelta(1000, $near['distance'], 10);
        $this->assertEqualsWithDelta(3109, $near['along'], 20);

        $this->assertSame([[56.0, 10.0], [56.1, 10.2]], RouteGeometry::parsePoints('[{"lat":56,"lng":10},{"lat":"x"},{"lat":56.1,"lng":10.2}]'));
    }
}
