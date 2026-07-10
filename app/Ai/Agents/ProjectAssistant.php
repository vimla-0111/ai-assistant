<?php

namespace App\Ai\Agents;

use App\Ai\Tools\QueryBuilderTool;
use App\Ai\Tools\ResumeSearchTool;
use App\Models\AgentConversationMessage;
use App\Models\User;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(5)]
class ProjectAssistant implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    private int $messageLimit = 2;

    private string $schemaContext = '';

    public function __construct(public User $user) {}

    /**
     * Attach relevant schema context to be injected into the instructions.
     * Returns the same instance for fluent chaining.
     */
    public function withSchemaContext(string $context): static
    {
        $this->schemaContext = $context;

        return $this;
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $base = 'You are a smart assistant that helps manage HR operations, candidate resumes, and application data. '
            .'Always use the provided tools to fetch real data instead of inventing facts. Summarize tool results clearly and concisely. '
            .'CRITICAL: Do not execute a tool more than twice for a single user query. If a tool fails to return the exact data you need (e.g. you search for a person and they are not in the results), ACCEPT that the data does not exist. Do not guess or run the tool again with different parameters. IMMEDIATELY tell the user you could not find the information. '
            .'If the user\'s request is ambiguous and you are not 100% sure whether to use the resume search tool or the database query tool, DO NOT guess. Ask the user to clarify which one they want to search.';

        if ($this->schemaContext !== '') {
            $base .= "\n\nThe following database tables are most relevant to the user's query. "
                ."Use ONLY these tables and their columns when building database queries:\n\n"
                .$this->schemaContext;
        }

        return $base;
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return Message[]
     */
    public function messages(): iterable
    {
        return AgentConversationMessage::where('user_id', $this->user->id)
            ->latest()
            ->limit($this->messageLimit)
            ->get()
            ->reverse()
            ->map(function ($message) {
                return new Message($message->role, $message->content);
            })->all();
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [new QueryBuilderTool, new ResumeSearchTool];
    }
}
