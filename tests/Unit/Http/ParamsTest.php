<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Exception\InvalidParameter;
use App\Http\Exception\ParameterMissing;
use App\Http\Params;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ParamsTest extends TestCase
{
    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function queries(): iterable
    {
        yield 'plain' => ['a=1&b=2', ['a' => '1', 'b' => '2']];
        yield 'no value' => ['a&b=', ['a' => null, 'b' => '']];
        yield 'plus and escapes' => ['q=a+b%20c%26', ['q' => 'a b c&']];
        yield 'dots kept' => ['a.b=1', ['a.b' => '1']];
        yield 'nested' => ['message[body]=hi&message[room][id]=3', ['message' => ['body' => 'hi', 'room' => ['id' => '3']]]];
        yield 'array' => ['ids[]=1&ids[]=2', ['ids' => ['1', '2']]];
        yield 'array of hashes' => ['u[][n]=a&u[][e]=x&u[][n]=b', ['u' => [['n' => 'a', 'e' => 'x'], ['n' => 'b']]]];
        yield 'last wins' => ['a=1&a=2', ['a' => '2']];
        yield 'leading bracket' => ['[a]=1&b=2', ['[a]' => '1', 'b' => '2']];
        yield 'empty key' => ['=1&b=2', ['b' => '2']];
        yield 'separator spaces' => ['a=1&  b=2', ['a' => '1', 'b' => '2']];
        yield 'empty parts' => ['&&a=1&', ['a' => '1']];
        yield 'nil in array dropped' => ['a[]&a[]=1', ['a' => ['1']]];
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('queries')]
    public function testParsesQueryStringsLikeRails(string $query, array $expected): void
    {
        self::assertSame($expected, Params::parseQuery($query));
    }

    public function testTypeConflictsAreBadRequests(): void
    {
        $this->expectException(InvalidParameter::class);
        Params::parseQuery('a=1&a[b]=2');
    }

    public function testMalformedEscapesAreBadRequests(): void
    {
        $this->expectException(InvalidParameter::class);
        Params::parseQuery('a=%zz');
    }

    public function testMergesBodyQueryAndPathInRailsOrder(): void
    {
        $request = Request::create('/rooms/5?x=query&room_id=query', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'x=body&y=body&message[body]=hi');
        $request->attributes->set('_route_params', ['room_id' => '5', '_format' => 'json', '_controller' => 'x']);

        $params = Params::fromRequest($request);
        self::assertSame('query', $params->get('x'));
        self::assertSame('body', $params->get('y'));
        self::assertSame('5', $params->get('room_id'));
        self::assertSame('json', $params->get('format'));
        self::assertSame('hi', $params->get('message.body'));
        self::assertSame($params, Params::fromRequest($request));
    }

    public function testJsonBodies(): void
    {
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"a":{"b":1}}');
        self::assertSame(1, Params::fromRequest($request)->get('a.b'));

        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '[1,2]');
        self::assertSame([1, 2], Params::fromRequest($request)->get('_json'));

        $this->expectException(InvalidParameter::class);
        Params::fromRequest(Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{'));
    }

    public function testRequireFetchAndPermit(): void
    {
        $params = new Params(['user' => ['name' => 'Ann', 'role' => 'admin', 'tags' => ['a', 'b'], 'x' => ['y' => 1]], 'blank' => ' ', 'flag' => false]);

        $user = $params->require('user');
        self::assertInstanceOf(Params::class, $user);
        self::assertSame(['name' => 'Ann', 'tags' => ['a', 'b']], $user->permit('name', ['tags' => []], 'x'));
        self::assertSame(['name' => 'Ann', 'x' => ['y' => 1]], $user->permit('name', ['x' => ['y']]));
        self::assertSame(['name' => 'Ann'], $params->expect('user', ['name']));
        self::assertFalse($params->require('flag'));
        self::assertSame('d', $params->fetch('missing', 'd'));

        $this->expectException(ParameterMissing::class);
        $params->require('blank');
    }

    public function testFetchWithoutDefaultRaises(): void
    {
        $this->expectException(ParameterMissing::class);
        (new Params([]))->fetch('missing');
    }
}
