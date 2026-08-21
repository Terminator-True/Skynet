<?php

namespace Tests\Eval;

/**
 * One eval prompt + its acceptance rule.
 *
 * Pass criteria (spec): correct tool name AND schema-valid arguments that are
 * semantically consistent with the prompt — OR, for no-tool cases, a direct
 * natural-language answer with zero tool calls.
 */
final class EvalCase
{
    /**
     * @param  string  $prompt  user message sent to the assistant
     * @param  string|null  $expectedToolName  null == no-tool case
     * @param  \Closure(array{name:string,arguments:array<string,mixed>,result:array<string,mixed>}): bool|null  $semantics
     *                                                                                                                       extra per-prompt consistency rule over the winning call
     * @param  string  $note  what this case stresses
     */
    public function __construct(
        public readonly string $prompt,
        public readonly ?string $expectedToolName,
        public readonly ?\Closure $semantics = null,
        public readonly string $note = '',
    ) {}

    /** @param array{reply:string,tool_calls:list<array{name:string,arguments:array,schemaValid:bool,result:array}>} $turn */
    public function judge(array $turn): bool
    {
        $calls = $turn['tool_calls'];

        if ($this->expectedToolName === null) {
            return $calls === [] && trim($turn['reply']) !== '';
        }

        foreach ($calls as $call) {
            if ($call['name'] !== $this->expectedToolName || ! ($call['schemaValid'])) {
                continue;
            }

            if ($this->semantics === null || ($this->semantics)($call)) {
                return true;
            }
        }

        return false;
    }
}
