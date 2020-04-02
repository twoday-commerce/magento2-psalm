<?php

/**
 * @copyright Visma Digital Commerce AS 2020
 * @license   MIT
 * @author    Marcus Pettersen Irgens <marcus.irgens@visma.com>
 */

declare(strict_types=1);

namespace Visma\Magento2Psalm\Exception;

use Psalm\Issue\CodeIssue;
use Throwable;

class IssueException extends \Exception
{
    /**
     * @var \Psalm\Issue\CodeIssue
     */
    protected $issue;

    public function __construct(CodeIssue $issue, int $code = 0, Throwable $previous = null)
    {
        parent::__construct($issue->getMessage(), $code, $previous);
        $this->issue = $issue;
    }

    /**
     * @return \Psalm\Issue\CodeIssue
     */
    public function getIssue(): \Psalm\Issue\CodeIssue
    {
        return $this->issue;
    }
}
