<?php

/**
 * @copyright Visma Digital Commerce AS 2020
 * @license   MIT
 * @author    Marcus Pettersen Irgens <marcus.irgens@visma.com>
 */

declare(strict_types=1);

namespace Visma\Magento2Psalm\Translate;

use PhpParser\Node\Expr\FuncCall;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\FileManipulation;
use Psalm\IssueBuffer;
use Psalm\Plugin\Hook\AfterFunctionCallAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Type\Union;

class TranslatePluginHook implements AfterFunctionCallAnalysisInterface
{
    public static function afterFunctionCallAnalysis(
        FuncCall $expr,
        string $function_id,
        Context $context,
        StatementsSource $statements_source,
        Codebase $codebase,
        array &$file_replacements = [],
        Union &$return_type_candidate = null
    ) {
        if ($function_id !== "__") {
            return;
        }

        // $analysis = new \Visma\Magento2Psalm\Translate\TranslateAnalysis();
        $analysisNeue = TranslationAnalyser::analyse($expr, $function_id, $context, $statements_source, $codebase);

        // foreach ($analysis->analyse($expr, $statements_source, $context) as $issue) {
        //     if(IssueBuffer::accepts($issue, $statements_source->getSuppressedIssues())) {
        //         // noop
        //     }
        // }
    }
}
