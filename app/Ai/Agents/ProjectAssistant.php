<?php

namespace App\Ai\Agents;

use App\Ai\Tools\DatabaseQueryTool;
use App\Models\AgentConversationMessage;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

class ProjectAssistant implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable, RemembersConversations;

    private $messageLimit = 10;

    public function __construct(public User $user) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return 'You are a Product assistant. Answer questions about the user\'s accurately. Only share information returned by your tools. Be honest and accurate.';
        return `You are a smart database assistant. You have access to a tool that runs MySQL queries and returns results.

## Your Job
Understand the user's question, translate it into a correct MySQL query, execute it, and respond in clear natural language.

## Rules
1. Always inspect the schema first if you're unsure about table/column names before querying.
2. Only run SELECT queries. Never INSERT, UPDATE, DELETE, DROP, or ALTER.
3. Use LIMIT (default 50) unless the user explicitly asks for more.
4. Never expose raw SQL in your response unless the user asks for it.
5. Respond in the same language the user used.

## DB Schema
<schema>
` . Storage::disk('local')->get('ai/context/database_schema.txt') . `
</schema>

## Response Format
- Answer in natural language (e.g. "There are 142 interviewee registered this mont.")
- For lists/tables of data, present them in a readable format.
- If no results found, say so clearly.
- If the question is ambiguous, ask for clarification before querying.`;
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
        return [new DatabaseQueryTool];
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'value' => $schema->string()->required(),
        ];
    }
}
