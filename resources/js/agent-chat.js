export default function registerAgentChat(Alpine) {
    Alpine.data('agentChat', ({ endpoint, initialMessages = [] }) => ({
        endpoint,
        prompt: '',
        messages: initialMessages,
        isSending: false,
        error: null,

        init() {
            this.scrollToBottom();
        },

        fillPrompt(prompt) {
            this.prompt = prompt;

            this.$nextTick(() => {
                this.$refs.prompt?.focus();
            });
        },

        formatTimestamp(timestamp) {
            if (! timestamp) {
                return 'Now';
            }

            return new Intl.DateTimeFormat(undefined, {
                hour: 'numeric',
                minute: '2-digit',
                month: 'short',
                day: 'numeric',
            }).format(new Date(timestamp));
        },

        submitOnEnter(event) {
            if (event.shiftKey) {
                return;
            }

            event.preventDefault();
            this.submit();
        },

        async submit() {
            const prompt = this.prompt.trim();

            if (this.isSending || prompt.length === 0) {
                return;
            }

            this.error = null;
            this.isSending = true;

            this.messages.push({
                id: `user-${Date.now()}`,
                role: 'user',
                content: prompt,
                created_at: new Date().toISOString(),
            });

            this.prompt = '';
            this.scrollToBottom();

            try {
                const response = await window.axios.post(
                    this.endpoint,
                    { prompt },
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                        },
                    },
                );

                this.messages.push({
                    id: `assistant-${Date.now()}`,
                    role: 'assistant',
                    content: response.data.answer,
                    created_at: new Date().toISOString(),
                });

                this.scrollToBottom();
            } catch (error) {
                this.error = error.response?.data?.errors?.prompt?.[0]
                    ?? error.response?.data?.message
                    ?? 'Something went wrong while contacting the agent.';
            } finally {
                this.isSending = false;
                this.$nextTick(() => {
                    this.$refs.prompt?.focus();
                    this.scrollToBottom();
                });
            }
        },

        scrollToBottom() {
            this.$nextTick(() => {
                if (! this.$refs.messages) {
                    return;
                }

                this.$refs.messages.scrollTop = this.$refs.messages.scrollHeight;
            });
        },
    }));
}
