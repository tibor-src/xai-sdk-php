<?php

declare(strict_types=1);

use XaiOfficial\Sdk\Tools\Tools;

it('builds web search tool', function () {
    expect(Tools::webSearch(['allowed_domains' => ['example.com']]))
        ->toBe(['allowed_domains' => ['example.com'], 'type' => 'web_search']);
});

it('builds x search tool', function () {
    expect(Tools::xSearch())->toBe(['type' => 'x_search']);
});

it('builds code execution tool', function () {
    expect(Tools::codeExecution())->toBe(['type' => 'code_interpreter']);
});

it('builds collections search tool', function () {
    expect(Tools::collectionsSearch(['vector_store_ids' => ['vs_1']]))
        ->toBe(['vector_store_ids' => ['vs_1'], 'type' => 'file_search']);
});

it('builds mcp tool', function () {
    expect(Tools::mcp(['server_url' => 'https://mcp.example', 'server_label' => 'demo']))
        ->toBe(['server_url' => 'https://mcp.example', 'server_label' => 'demo', 'type' => 'mcp']);
});

it('builds image generation tool', function () {
    expect(Tools::imageGeneration(['action' => 'edit']))
        ->toBe(['action' => 'edit', 'type' => 'image_generation']);
});

it('builds tool search', function () {
    expect(Tools::toolSearch())->toBe(['type' => 'tool_search']);
});
