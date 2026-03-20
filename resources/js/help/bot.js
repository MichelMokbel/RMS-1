window.helpBotWidget = function helpBotWidget(config = {}) {
    return {
        open: false,
        loading: false,
        sessionId: null,
        draft: '',
        messages: [
            {
                role: 'assistant',
                content: 'Ask about a workflow and I will answer only from the approved Help Center guides.',
                citations: [],
                suggestions: [],
                fallback: false,
            },
        ],
        articleSlug: config.articleSlug || null,
        endpoint: config.endpoint,
        csrf: config.csrf,

        normalizeCitations(citations) {
            if (!Array.isArray(citations)) {
                return [];
            }

            const seen = new Set();

            return citations.filter((citation) => {
                const key = citation?.article_slug || citation?.article_title;

                if (!key || seen.has(key)) {
                    return false;
                }

                seen.add(key);

                return true;
            });
        },

        normalizeSuggestions(suggestions) {
            if (!Array.isArray(suggestions)) {
                return [];
            }

            const seen = new Set();

            return suggestions.filter((suggestion) => {
                const key = suggestion?.slug || suggestion?.url || suggestion?.title;

                if (!key || seen.has(key)) {
                    return false;
                }

                seen.add(key);

                return true;
            });
        },

        async send() {
            const message = this.draft.trim();
            if (!message || this.loading) {
                return;
            }

            this.messages.push({
                role: 'user',
                content: message,
                citations: [],
                suggestions: [],
                fallback: false,
            });
            this.draft = '';
            this.loading = true;

            try {
                const response = await fetch(this.endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': this.csrf,
                    },
                    body: JSON.stringify({
                        message,
                        session_id: this.sessionId,
                        article_slug: this.articleSlug,
                    }),
                });

                const payload = await response.json();
                this.sessionId = payload.session_id || this.sessionId;

                this.messages.push({
                    role: 'assistant',
                    content: payload.answer_markdown || 'No answer returned.',
                    citations: this.normalizeCitations(payload.citations),
                    suggestions: this.normalizeSuggestions(payload.suggested_articles),
                    fallback: Boolean(payload.fallback),
                });
            } catch (error) {
                this.messages.push({
                    role: 'assistant',
                    content: 'The Help bot could not be reached right now.',
                    citations: [],
                    suggestions: [],
                    fallback: true,
                });
            } finally {
                this.loading = false;
                requestAnimationFrame(() => {
                    const viewport = this.$refs.viewport;
                    if (viewport) {
                        viewport.scrollTop = viewport.scrollHeight;
                    }
                });
            }
        },

        reset() {
            this.sessionId = null;
            this.messages = [
                {
                    role: 'assistant',
                    content: 'Ask about a workflow and I will answer only from the approved Help Center guides.',
                    citations: [],
                    suggestions: [],
                    fallback: false,
                },
            ];
        },
    };
};
