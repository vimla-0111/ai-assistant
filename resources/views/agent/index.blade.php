<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    {{ __('Agent') }}
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ __('Ask the AI assistant questions about your project data and workflow.') }}
                </p>
            </div>
            <div class="hidden rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-medium uppercase tracking-[0.24em] text-emerald-700 sm:block">
                {{ __('Live Session') }}
            </div>
        </div>
    </x-slot>

    <div class="relative overflow-hidden bg-slate-950 py-10">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,_rgba(45,212,191,0.18),_transparent_34%),radial-gradient(circle_at_bottom_right,_rgba(249,115,22,0.22),_transparent_30%)]"></div>

        <div class="relative mx-auto grid max-w-7xl gap-6 px-4 sm:px-6 lg:grid-cols-[320px,minmax(0,1fr)] lg:px-8">
            <section class="overflow-hidden rounded-[28px] border border-white/10 bg-white/10 text-white shadow-2xl shadow-slate-950/30 backdrop-blur">
                <div class="border-b border-white/10 px-6 py-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-teal-200">
                        {{ __('Chat Guide') }}
                    </p>
                    <h3 class="mt-3 text-2xl font-semibold leading-tight">
                        {{ __('Use natural prompts and keep the conversation moving.') }}
                    </h3>
                </div>

                <div class="space-y-5 px-6 py-6">
                    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4">
                        <p class="text-sm font-medium text-slate-100">{{ __('What works well here') }}</p>
                        <ul class="mt-3 space-y-2 text-sm text-slate-300">
                            <li>{{ __('Ask follow-up questions in the same thread.') }}</li>
                            <li>{{ __('Request summaries, lists, or database-backed answers.') }}</li>
                            <li>{{ __('Keep prompts specific when you need exact results.') }}</li>
                        </ul>
                    </div>

                    <div class="rounded-2xl border border-amber-300/20 bg-amber-300/10 p-4">
                        <p class="text-sm font-medium text-amber-100">{{ __('Starter prompts') }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ([
                                'Show active departments and their users.',
                                'Summarize the latest records added today.',
                                'List the data I should review before exporting.',
                            ] as $starterPrompt)
                                <button
                                    type="button"
                                    class="rounded-full border border-amber-200/20 bg-white/10 px-3 py-2 text-left text-xs text-amber-50 transition hover:bg-white/15"
                                    x-on:click="$dispatch('agent-fill-prompt', @js($starterPrompt))"
                                >
                                    {{ $starterPrompt }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </section>

            <section
                x-data="agentChat({
                    endpoint: @js(route('agent.prompt')),
                    initialMessages: @js($messages),
                })"
                x-on:agent-fill-prompt.window="fillPrompt($event.detail)"
                class="flex min-h-[720px] flex-col overflow-hidden rounded-[32px] border border-slate-200 bg-white shadow-[0_24px_80px_rgba(15,23,42,0.14)]"
            >
                <div class="flex items-center justify-between gap-4 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:px-6">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-500">{{ __('Conversation') }}</p>
                        <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ __('Project Assistant') }}</h3>
                    </div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600">
                        <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                        <span>{{ __('Ready') }}</span>
                    </div>
                </div>

                <div x-ref="messages" class="flex-1 space-y-6 overflow-y-auto bg-[linear-gradient(180deg,#f8fafc_0%,#ffffff_30%,#f8fafc_100%)] px-5 py-6 sm:px-6">
                    <template x-if="messages.length === 0">
                        <div class="flex h-full items-center justify-center">
                            <div class="max-w-md rounded-[28px] border border-dashed border-slate-300 bg-white/80 p-8 text-center shadow-sm">
                                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-900 text-white shadow-lg shadow-slate-300/60">
                                    <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5M6 20l-2-2V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6Z" />
                                    </svg>
                                </div>
                                <h4 class="mt-5 text-xl font-semibold text-slate-900">{{ __('Start the conversation') }}</h4>
                                <p class="mt-3 text-sm leading-6 text-slate-500">
                                    {{ __('Ask a question below and your assistant response will appear here in real time.') }}
                                </p>
                            </div>
                        </div>
                    </template>

                    <template x-for="(message, index) in messages" :key="message.id ?? `${message.role}-${index}`">
                        <article class="flex" :class="message.role === 'user' ? 'justify-end' : 'justify-start'">
                            <div class="max-w-3xl space-y-2">
                                <div class="flex items-center gap-2 text-xs font-medium uppercase tracking-[0.24em]" :class="message.role === 'user' ? 'justify-end text-slate-400' : 'text-teal-700'">
                                    <span x-text="message.role === 'user' ? '{{ __('You') }}' : '{{ __('Assistant') }}'"></span>
                                    <span class="text-slate-300">•</span>
                                    <span class="tracking-normal text-slate-400" x-text="formatTimestamp(message.created_at)"></span>
                                </div>

                                <div
                                    class="rounded-[24px] px-5 py-4 text-sm leading-7 shadow-sm"
                                    :class="message.role === 'user'
                                        ? 'bg-slate-900 text-white shadow-slate-950/20'
                                        : 'border border-slate-200 bg-white text-slate-700 shadow-slate-200/70'"
                                >
                                    <template x-if="message.role === 'user'">
                                        <p class="whitespace-pre-wrap" x-text="message.content"></p>
                                    </template>
                                    <template x-if="message.role === 'assistant'">
                                        <div 
                                            class="max-w-none space-y-3 [&_strong]:font-semibold [&_em]:italic [&_h1]:text-lg [&_h1]:font-bold [&_h2]:text-base [&_h2]:font-bold [&_h3]:text-sm [&_h3]:font-bold [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5 [&_li]:ml-2 [&_code]:bg-slate-100 [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:rounded [&_code]:text-xs [&_code]:font-mono [&_table]:w-full [&_table]:border [&_th]:border [&_th]:px-3 [&_th]:py-2 [&_th]:bg-slate-50 [&_th]:font-semibold [&_td]:border [&_td]:px-3 [&_td]:py-2"
                                            x-html="renderMarkdown(message.content)"
                                        ></div>
                                    </template>
                                </div>
                            </div>
                        </article>
                    </template>

                    <template x-if="isSending">
                        <article class="flex justify-start">
                            <div class="max-w-xl rounded-[24px] border border-slate-200 bg-white px-5 py-4 text-sm text-slate-500 shadow-sm">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex gap-1">
                                        <span class="h-2 w-2 animate-bounce rounded-full bg-teal-500 [animation-delay:-0.3s]"></span>
                                        <span class="h-2 w-2 animate-bounce rounded-full bg-teal-500 [animation-delay:-0.15s]"></span>
                                        <span class="h-2 w-2 animate-bounce rounded-full bg-teal-500"></span>
                                    </span>
                                    <span>{{ __('Assistant is thinking...') }}</span>
                                </div>
                            </div>
                        </article>
                    </template>
                </div>

                <div class="border-t border-slate-200 bg-white px-5 py-5 sm:px-6">
                    <div x-show="error" x-cloak class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700" x-text="error"></div>

                    <form class="space-y-4" x-on:submit.prevent="submit()">
                        <label for="agent-prompt" class="sr-only">{{ __('Prompt') }}</label>
                        <textarea
                            id="agent-prompt"
                            x-ref="prompt"
                            x-model="prompt"
                            rows="4"
                            class="w-full rounded-[24px] border border-slate-300 bg-slate-50 px-5 py-4 text-sm text-slate-800 shadow-inner shadow-slate-200/70 outline-none transition placeholder:text-slate-400 focus:border-teal-500 focus:bg-white focus:ring-2 focus:ring-teal-200"
                            placeholder="{{ __('Ask the assistant something useful...') }}"
                            x-on:keydown.enter="submitOnEnter($event)"
                        ></textarea>

                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm text-slate-500">
                                {{ __('Press Enter to send, or Shift + Enter for a new line.') }}
                            </p>

                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-full bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-400"
                                :disabled="isSending || prompt.trim().length === 0"
                            >
                                <span x-show="! isSending">{{ __('Send Message') }}</span>
                                <span x-show="isSending" x-cloak>{{ __('Sending...') }}</span>
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
