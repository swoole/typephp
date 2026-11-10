<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Context;

/** Function-entry storage facts to restore after speculative SSA lowering. */
final class AnalysisCheckpoint
{
    private array $localVars;
    private int $tmpVarIndex;
    private array $declaredObjects;
    private array $nativeObjects;
    private array $nonNullNativeObjects;

    public function __construct(FunctionContext $context)
    {
        $this->localVars = $context->localVars;
        $this->tmpVarIndex = $context->tmpVarIndex;
        $this->declaredObjects = $context->declaredObjects;
        $this->nativeObjects = $context->nativeObjects;
        $this->nonNullNativeObjects = $context->nonNullNativeObjects;
    }

    public function restore(FunctionContext $context): void
    {
        $context->localVars = $this->localVars;
        $context->tmpVarIndex = $this->tmpVarIndex;
        $context->declaredObjects = $this->declaredObjects;
        $context->nativeObjects = $this->nativeObjects;
        $context->nonNullNativeObjects = $this->nonNullNativeObjects;
    }
}
