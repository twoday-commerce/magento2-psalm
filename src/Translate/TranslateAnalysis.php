<?php

/**
 * @copyright Visma Digital Commerce AS 2020
 * @license   MIT
 * @author    Marcus Pettersen Irgens <marcus.irgens@visma.com>
 */

declare(strict_types=1);

namespace Visma\Magento2Psalm\Translate;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Issue\InvalidArgument;
use Psalm\Issue\TooFewArguments;
use Psalm\Issue\TooManyArguments;
use Psalm\StatementsSource;
use Visma\Magento2Psalm\Translate\Exception\ExpectedArrayException;
use Visma\Magento2Psalm\Translate\Exception\ExpectedStringException;
use Visma\Magento2Psalm\Translate\Exception\InvalidArgumentCountException;
use Visma\Magento2Psalm\Translate\Exception\InvalidPlaceholderKeyException;
use Visma\Magento2Psalm\Translate\Exception\MissingPlaceholderKeyException;
use Visma\Magento2Psalm\Translate\Exception\UnprintableValueException;

class TranslateAnalysis
{
    private const UNKNOWN_PLACEHOLDERS = 1;
    private const STRING_PLACEHOLDERS = 1<<1;
    private const NUMERIC_PLACEHOLDERS = 1<<2;
    private const NUMERIC_ARRAY_PARAMS = 1<<3;
    private const NUMERIC_REGULAR_PARAMS = 1<<4;

    /**
     * @param \PhpParser\Node\Expr\FuncCall $call
     * @param \Psalm\StatementsSource       $source
     * @return \Psalm\Issue\CodeIssue[]
     * @throws \Exception
     */
    public function analyse(FuncCall $call, StatementsSource $source, \Psalm\Context $context): array
    {
        $issues = [];

        $variant = $this->getVariant($call, $source, $context);

        $name = $call->name;
        if (!($name instanceof \PhpParser\Node\Name)) {
            throw new \Exception("Could not get the function name");
        }
        if ($name->getLast() !== "__") {
            throw new \Exception("Can only analyse the \"__\" translation method");
        }

        try {
            $this->checkStringArg($call, $source, $context);
        } catch (ExpectedStringException $e) {
            $issues[] = $e->getIssue();
            // Unrecoverable, cannot analyse invalid strings.
            return $issues;
        }
        $isNamed = $this->checkIsNamed($call);
        try {
            if (!$isNamed) {
                $this->checkNumericPlaceholders($call, $source, $context);
            } else {
                $this->checkStringPlaceholders($call, $source, $context);
            }
        } catch (UnprintableValueException $e) {
            $issues[] = $e->getIssue();
        } catch (InvalidArgumentCountException $e) {
            $issues[] = $e->getIssue();
        } catch (InvalidPlaceholderKeyException $e) {
            $issues[] = $e->getIssue();
        } catch (MissingPlaceholderKeyException $e) {
            $issues[] = $e->getIssue();
        }

        return $issues;
    }

    /**
     * Check that the first argument is a string
     *
     * @param \PhpParser\Node\Expr\FuncCall $call
     * @param \Psalm\StatementsSource       $source
     * @param \Psalm\Context                $context
     * @throws \Visma\Magento2Psalm\Translate\Exception\ExpectedStringException
     */
    private function checkStringArg(FuncCall $call, StatementsSource $source, \Psalm\Context $context): void
    {
        $args = $call->args;
        if (count($args) === 0) {
            throw new \LogicException("Cannot analyse with an empty argument list");
        }
        $first = array_shift($args);

        /** @var \PhpParser\Node\Expr $value */
        $value = $first->value;
        $type = $value->getType();
        if ($value instanceof Expr\Variable) {
            $this->checkVariableIsStringable($value, $context);
        } elseif ($value instanceof String_) {
        } else {
            throw new ExpectedStringException(
                new InvalidArgument(
                    "The first param to __ must be a string, {$type} given",
                    new CodeLocation($source, $call)
                )
            );
        }
    }

    /**
     * Check if the placeholders are named (eg non-numeric)
     *
     * @param \PhpParser\Node\Arg $arg
     * @return bool
     */
    private function checkIsNamed(FuncCall $call): bool
    {
        $arg = $call->args[0];
        if (!$arg->value instanceof String_) {
            throw new \LogicException(__METHOD__ . " expected a string");
        }
        $placeholders = $this->getPlaceholders($call);

        $i = 1;
        foreach ($placeholders as $placeholder) {
            if ($placeholder !== (string)$i++) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $string
     * @return string[]
     */
    private function getPlaceholders(FuncCall $call): array
    {
        $arg = $call->args[0];
        if (!$arg->value instanceof String_) {
            throw new \LogicException(__METHOD__ . " expected a string");
        }
        $value = $arg->value->value;

        $re = '/%[a-zA-Z0-9]+/';

        if (!preg_match_all($re, $value, $matches)) {
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
     * @param \PhpParser\Node\Expr\FuncCall $call
     * @param \Psalm\StatementsSource       $source
     * @throws \Visma\Magento2Psalm\Translate\Exception\InvalidArgumentCountException
     * @throws \Visma\Magento2Psalm\Translate\Exception\UnprintableValueException
     */
    private function checkNumericPlaceholders(FuncCall $call, StatementsSource $source, Context $context): void
    {
        // Expecting one of the following:
        // 1. The second parameter is an array, with an equal number of items as
        //    the number of placeholders, and they can all be stringed
        // 2. The number of parameters is one more than the number of
        //    placeholders, and they are all scalar types or can be stringed
        // 3. There are no placeholders, and no parameters

        // Get all the function parameters and shift off the first (the base string)
        $params = $call->args;
        $template = array_shift($params);

        $placeholders = $this->getPlaceholders($call);
        $itemsToPrint = [];

        if (count($params) === 0) {
            // do nothing
        } else {
            $firstParam = $params[0]->value;
            // The first additional parameter is either an array...
            if ($firstParam instanceof Array_) {
                // TODO: unnest this
                if (count($params) > 1) {
                    throw new InvalidArgumentCountException(
                        new TooManyArguments(
                            "When the second argument to __ is an array, it must be the only additional argument",
                            new CodeLocation($source, $call)
                        )
                    );
                }

                foreach ($firstParam->items as $arrayItem) {
                    if ($arrayItem instanceof Expr\ArrayItem) {
                        $itemsToPrint[] = $arrayItem->value;
                    }
                }
            } else {
                foreach ($params as $arg) {
                    $itemsToPrint[] = $arg->value;
                }
            }
        }

        $pc = count($placeholders);
        $tp = count($itemsToPrint);
        if ($pc > $tp) {
            throw new InvalidArgumentCountException(
                new TooFewArguments(
                    "Template string has {$pc} placeholders, only {$tp} arguments passed to __",
                    new CodeLocation($source, $call)
                )
            );
        } elseif ($pc < $tp) {
            throw new InvalidArgumentCountException(
                new TooManyArguments(
                    "Template string has {$pc} placeholders, only {$tp} arguments passed to __",
                    new CodeLocation($source, $call)
                )
            );
        }

        if (!$this->checkAllValuesArePrintable($itemsToPrint, $context)) {
            throw new UnprintableValueException(
                new InvalidArgument(
                    "Parameter passed to __ is not printable",
                    new CodeLocation($source, $call)
                )
            );
        }
    }

    /**
     * @param \PhpParser\Node\Expr\FuncCall $call
     * @param \Psalm\StatementsSource       $source
     * @throws \Visma\Magento2Psalm\Translate\Exception\InvalidPlaceholderKeyException
     * @throws \Visma\Magento2Psalm\Translate\Exception\MissingPlaceholderKeyException
     * @throws \Visma\Magento2Psalm\Translate\Exception\UnprintableValueException
     */
    private function checkStringPlaceholders(FuncCall $call, StatementsSource $source, Context $context): void
    {
        // Expecting the second parameter to be an array, with an equal number
        // of items as the number of placeholders, and they can all be stringed.
        // All placeholder names must exist in the array.

        $params = $call->args;
        array_shift($params);
        if (count($params) !== 0) {
            // todo throw
        }

        $placeholders = $this->getPlaceholders($call);

        $args = $params[0]->value;
        if (!$args instanceof Array_) {
            throw new ExpectedArrayException(
                new InvalidArgument(
                    "Expected the second parameter to __ to be an array",
                    new CodeLocation($source, $call)
                )
            );
        }

        $this->checkAllPlaceholdersExist($placeholders, $args, $call, $source);

        $itemsToPrint = [];
        foreach ($args->items as $item) {
            if ($item instanceof Expr\ArrayItem) {
                $itemsToPrint[] = $item->value;
            }
        }

        if (!$this->checkAllValuesArePrintable($itemsToPrint, $context)) {
            throw new UnprintableValueException(
                new InvalidArgument(
                    "Parameter passed to __ is not printable",
                    new CodeLocation($source, $call)
                )
            );
        }
    }

    /**
     * @param string[]                      $placeholders
     * @param \PhpParser\Node\Expr\Array_   $args
     * @param \PhpParser\Node\Expr\FuncCall $call
     * @param \Psalm\StatementsSource       $source
     * @throws \Visma\Magento2Psalm\Translate\Exception\InvalidPlaceholderKeyException
     * @throws \Visma\Magento2Psalm\Translate\Exception\MissingPlaceholderKeyException
     */
    private function checkAllPlaceholdersExist(
        array $placeholders,
        \PhpParser\Node\Expr\Array_ $args,
        FuncCall $call,
        StatementsSource $source
    ): void {
        foreach ($placeholders as $placeholder) {
            if (!$this->checkPlaceholderExists($placeholder, $args, $call, $source)) {
                throw new MissingPlaceholderKeyException(
                    new InvalidArgument("Missing placeholder value {$placeholder}", new CodeLocation($source, $call))
                );
            }
        }

        if (count($placeholders) !== count($args->items)) {
            // throw
        }
    }

    /**
     * @param string                        $placeholder
     * @param \PhpParser\Node\Expr\Array_   $args
     * @param \PhpParser\Node\Expr\FuncCall $call
     * @param \Psalm\StatementsSource       $source
     * @return bool
     * @throws \Visma\Magento2Psalm\Translate\Exception\InvalidPlaceholderKeyException
     */

    private function checkPlaceholderExists(
        string $placeholder,
        Array_ $args,
        FuncCall $call,
        StatementsSource $source
    ): bool {
        foreach ($args->items as $argument) {
            if (!$argument instanceof Expr\ArrayItem) {
                throw new \LogicException("Could not get ArrayItem");
            }
            $key = $argument->key;
            if (!$key instanceof String_) {
                throw new InvalidPlaceholderKeyException(
                    new InvalidArgument(
                        "Key of placeholder item must be a string",
                        new CodeLocation($source, $call)
                    )
                );
            }
            if ($key->value == $placeholder) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \PhpParser\Node\Expr[] $itemsToPrint
     */
    private function checkAllValuesArePrintable(array $itemsToPrint, Context $context): bool
    {
        foreach ($itemsToPrint as $item) {
            if (!$this->checkValueIsPrintable($item, $context)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param \PhpParser\Node\Expr $item
     * @return bool
     */
    private function checkValueIsPrintable(Expr $item, Context $context): bool
    {
        if ($item instanceof \PhpParser\Node\Scalar) {
            return true;
        }

        if ($item instanceof Expr\Variable) {
            $this->checkVariableIsStringable($item, $context);
        }

        // Unable to determine type.
        return true;
    }

    /**
     * @param \PhpParser\Node\Expr\Variable $type
     * @param \Psalm\Context                $context
     */
    private function checkVariableIsStringable(Expr\Variable $type, \Psalm\Context $context)
    {
        $name = '$' . $type->name;
        if (!is_string($name)) {
            // Can't do this.
            return;
        }

        if (!$context->hasVariable($name)) {
            // todo
            return;
        }

        /** @var \Psalm\Type\Union $var */
        $var = $context->vars_in_scope[$name];
        $string = $var->isString();
    }

    private function getVariant(FuncCall $call, StatementsSource $source, Context $context): int
    {
        $arguments = $call->args;
        $first = array_shift($arguments);
        $template = $first->value;

        $type = 0;

        // Handle the template being a variable
        if ($template instanceof Expr\Variable) {
            $type = $type | self::UNKNOWN_PLACEHOLDERS;
        }

        if ($template instanceof String_) {

        }

        return $type;
    }
}
