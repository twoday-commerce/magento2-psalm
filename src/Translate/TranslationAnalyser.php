<?php

/**
 * @copyright Visma Digital Commerce AS 2020
 * @license   Proprietary
 * @author    Marcus Pettersen Irgens <marcus.irgens@visma.com>
 */

declare(strict_types=1);

namespace Visma\Magento2Psalm\Translate;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\StatementsSource;
use Psalm\Type\TypeNode;
use Psalm\Type\Union;

class TranslationAnalyser
{
    /**
     * @var \PhpParser\Node\Expr\FuncCall
     */
    protected $expr;

    /**
     * @var string
     */
    protected $function_id;

    /**
     * @var \Psalm\Context
     */
    protected $context;

    /**
     * @var \Psalm\StatementsSource
     */
    protected $statements_source;

    /**
     * @var \Psalm\Codebase
     */
    protected $codebase;

    /**
     * @var string|null
     */
    private $template = null;

    /**
     * @var string[]
     */
    private $placeholders = [];

    protected function __construct(
        FuncCall $expr,
        string $function_id,
        Context $context,
        StatementsSource $statements_source,
        Codebase $codebase
    ) {
        $this->expr = $expr;
        $this->function_id = $function_id;
        $this->context = $context;
        $this->statements_source = $statements_source;
        $this->codebase = $codebase;
    }

    /**
     * @param \PhpParser\Node\Expr\FuncCall $expr
     * @param string                        $function_id
     * @param \Psalm\Context                $context
     * @param \Psalm\StatementsSource       $statements_source
     * @param \Psalm\Codebase               $codebase
     * @return \Psalm\Issue\CodeIssue[]
     */
    public static function analyse(
        FuncCall $expr,
        string $function_id,
        Context $context,
        StatementsSource $statements_source,
        Codebase $codebase
    ): array {
        $analyser = new static($expr, $function_id, $context, $statements_source, $codebase);
        $template = $analyser->extractTemplate();
        $analyser->setTemplate($template);

        if (($t = $analyser->getTemplate()) !== null) {
            $p = $analyser->getPlaceholders($t);
            $analyser->setPlaceholders($p);
        }

        $args = $analyser->getArguments();
        
        
    }

    /**
     * @param string|null $template
     */
    protected function setTemplate(?string $template): void
    {
        $this->template = $template;
    }

    protected function getTemplate(): ?string
    {
        return $this->template;
    }

    private function extractTemplate(): ?string
    {
        $templateVal = $this->getTemplateValue();

        if ($templateVal instanceof Expr\Variable) {
            return $this->getVal($templateVal);
        }

        if ($templateVal instanceof String_) {
            return $templateVal->value;
        }

        return null;
    }

    private function getTemplateValue(): Expr
    {
        if ((count($this->expr->args) < 1) || !array_key_exists(0, $this->expr->args)) {
            throw new \LogicException("Invalid number or formats of arguments to this method");
        }
        $templateArg = $this->expr->args[0];
        return $templateArg->value;
    }

    private function getVal(Expr\Variable $templateVal): ?string
    {
        $variableName = '$' . $templateVal->name;
        if (!$this->context->hasVariable($variableName)) {
            return null;
        }
        if (!array_key_exists($variableName, $this->context->vars_in_scope)) {
            return null;
        }
        $var = $this->context->vars_in_scope[$variableName];
        if (!$var instanceof Union) {
            return null;
        }

        if (!$var->isSingleStringLiteral()) {
            return null;
        }

        return $var->getSingleStringLiteral()->value;
    }

    /**
     * @param string $template
     * @return string[]
     */
    private function getPlaceholders(string $template): array
    {
        $re = '/%[a-zA-Z0-9]+/';

        if (!preg_match_all($re, $template, $matches)) {
            return [];
        }

        /** @psalm-var array<int, array<int, string>> $matches */
        $first = $matches[0];

        $placeholders = [];
        foreach ($first as $match) {
            $placeholders[] = substr($match, 1);
        }

        return $placeholders;
    }

    /**
     * @param array $placeholders
     */
    private function setPlaceholders(array $placeholders): void
    {
        $this->placeholders = $placeholders;
    }

    /**
     * If all items can be fetched, get arguments, else return null
     *
     * @return null|Expr[]
     */
    private function getArguments(): ?array
    {
        $args = $this->expr->args;
        // First is template
        array_shift($args);
        // strip keys
        $args = array_values($args);

        if (count($args) === 0) {
            return [];
        }


        if (($arr = $args[0]->value) instanceof Expr\Array_) {
            /** @var \PhpParser\Node\Expr\Array_ $arr */
            if (count($args) > 1) {
                // error
            }

            // Get inner values from the array
            $items = [];
            foreach ($arr->items as $item) {
                $key = $item->key;
                if ($key instanceof String_) {
                    $keyV = $key->value;
                    $items[$keyV] = $item->value;
                } else {
                    $items[] = $item->value;
                }
            }
            return $items;
        }

        $items = [];
        foreach ($args as $arg) {
            $items[] = $arg->value;
        }
        return $items;
    }
}
