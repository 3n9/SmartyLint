<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__.'/vendor/autoload.php';

use SmartyAst\Parser\SmartyParser;

$tpl = <<<'TPL'
{* comment *}
{extends file="base.tpl"}
{block name="body"}
  {strip}
    {assign var="items" value=[1,2,3]}
    {assign var="user" value=$smarty.session.user}
    {capture name="greeting"}Hello {$user.name|escape|upper}{/capture}
    {$greeting}

    {if $user.age >= 18 && $user.role == 'admin'}
      Access: admin
    {elseif $user.age >= 18 || $user.role eq 'staff'}
      Access: staff
        {else}
      Access: guest
        {/if}

    {for $i=0 to 3 step 1}
      Loop: {$i} {$items[$i]|default:'n/a'}
    {/for}

    {foreach from=$items item=it key=idx}
      Item {$idx}: {$it*2} {if $idx is even}even{else}odd{/if}
    {/foreach}

    {$smarty.now|date_format:"%Y-%m-%d"}
    {$foo->bar()->baz[0]->qux}
    {setfilter nocache}{literal}Raw { {$stuff} }{/literal}{/setfilter}
    {* Another comment *}
{block name="footer"}
    {include file="partial.tpl" scope="parent"}
  {/strip}
{/block}
TPL;

$parser = new SmartyParser;
$result = $parser->parseString($tpl);

echo $result->ast->toJson(JSON_PRETTY_PRINT).PHP_EOL;

if (! empty($result->diagnostics)) {
    echo PHP_EOL.'Diagnostics:'.PHP_EOL;
    foreach ($result->diagnostics as $diagnostic) {
        echo sprintf(
            "%s:%d:%d [%s] %s\n",
            $diagnostic->code,
            $diagnostic->span->start->line,
            $diagnostic->span->start->column,
            strtoupper($diagnostic->severity->value),
            $diagnostic->message,
        );
    }
}

echo "Done\n";
