<div class="relative w-full" id="chatgpt-agent-window" style="{{ $winWidth }}">
    <div class="fixed z-30 cursor-pointer" style="bottom: 1rem; right: 1rem;">
        <x-filament::button wire:click="togglePanel" id="btn-chat" :icon="$buttonIcon" :color="$panelHidden ? 'primary' : 'gray'">
            {{ $panelHidden ? __('chat.open') : __('chat.close') }}
        </x-filament::button>
    </div>

    <x-filament::section
        class="flex-1 p-2 sm:p-6 justify-between flex flex-col max-h-screen fixed right-1 bottom-0 bg-white shadow z-30 {{ $panelHidden ? 'hidden' : '' }}"
        style="{{ $winWidth }}" id="chat-window">

        <x-slot name="headerEnd">
                <x-filament::icon-button color="#FABB5A" icon="heroicon-s-minus" wire:click="togglePanel"
                                         label="{{ __('chat.hide_chat') }}"
                                         tooltip="{{ __('chat.hide_chat') }}" />
            {{__('chat.title')}}
        </x-slot>

        <div id="messages"
             wire:scroll
             wire:key="chatgpt-agent-messages"
             style="overflow: auto; min-height: max(20rem, 30vh); max-height: calc(100vh - 11rem); padding-bottom: 1rem; margin-bottom: 65px;"
             class="flex flex-col space-y-4 overflow-y-auto scrollbar-thumb-blue scrollbar-thumb-rounded scrollbar-track-blue-lighter scrollbar-w-2 scrolling-touch">
            @foreach ($messages as $message)
                <div wire:key="chatgpt-agent-message-{{ $loop->index }}">
                    @if ($message->user_id == $service->vendor->user_id)
                        <div class="chat-message">
                            <div class="flex items-end">
                                <div class="flex flex-col space-y-2 text-xs mx-2 order-2 items-start">
                                    <div>
                                        <div class="px-4 py-2 rounded-lg block rounded-bl-none bg-gray-300 text-gray-600">
                                            {{$this->decryptData($message->message)}}
                                        </div>
                                        <span class="fi-color-gray">{{$message->created_at}}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="chat-message">
                            <div class="flex items-end justify-end">
                                <div class="flex flex-col space-y-2 text-xs max-w-xs mx-2 order-1 items-end">
                                    <div>
                                        <div class="px-4 py-2 rounded-lg block rounded-br-none bg-[#FABB5A] text-white">
                                            {{$this->decryptData($message->message)}}
                                        </div>
                                        <span class="fi-color-gray">{{$message->created_at}}</span>
                                    </div>
                                </div>
                                @if(auth()->user() && method_exists(auth()->user(), 'getFilamentAvatarUrl') && auth()->user()->getFilamentAvatarUrl())
                                    <x-filament::avatar size="sm" :src="auth()->user()?->getFilamentAvatarUrl()" />
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <style>
        .scrollbar-w-2::-webkit-scrollbar {
            width: 0.5rem;
            height: 0.5rem;
        }

        .scrollbar-track-blue-lighter::-webkit-scrollbar-track {
            --bg-opacity: 1;
            background-color: #f7fafc;
            background-color: rgba(247, 250, 252, var(--bg-opacity));
        }

        .scrollbar-thumb-blue::-webkit-scrollbar-thumb {
            --bg-opacity: 1;
            background-color: #edf2f7;
            background-color: rgba(237, 242, 247, var(--bg-opacity));
        }

        .scrollbar-thumb-rounded::-webkit-scrollbar-thumb {
            border-radius: 0.25rem;
        }

        .bg-blue-600 {
            --tw-bg-opacity: 1;
            background-color: rgb(37 99 235 / var(--tw-bg-opacity));
        }

        .order-2 {
            order: 2;
        }

        .mx-2 {
            margin-left: 0.5rem;
            margin-right: 0.5rem;
        }

        .border-0 {
            border-width: 0px;
        }

        .rounded-br-right {
            border-bottom-right-radius: 0px;
        }

        .rounded-sm {
            border-radius: 0.125rem;
        }

        .p-1 {
            padding: 0.25rem;
        }

        .pl-1 {
            padding-left: 0.25rem;
        }

        .pl-2 {
            padding-left: 0.5rem;
        }

        .pt-4 {
            padding-top: 1rem;
        }

        .h-\[30px\] {
            height: 30px;
        }

        .w-\[30px\] {
            width: 30px;
        }

        .right-0 {
            right: 0;
        }

        .left-0 {
            left: 0;
        }

        .right-1 {
            right: 0.25rem;
        }

        .md\:right-2 {
            right: 0.5rem;
        }

        .max-h-screen {
            max-height: 100vh;
        }

        .chat-message blockquote {
            padding: 0.5rem 1rem;
            margin: 0.5rem 0;
            border-left: 3px solid #ccc;
        }

        .chat-message ul {
            list-style-type: circle;
            padding-left: 1rem;
        }

        .chat-message ol {
            list-style-type: decimal;
            padding-left: 1rem;
        }

        .chat-message strong {
            font-weight: 600;
        }

        .chat-message em {
            font-style: italic;
        }

        .chat-message code {
            background-color: #f4f4f4;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
        }

        .chat-message pre {
            background-color: #f4f4f4;
            padding: 0.5rem;
            border-radius: 4px;
            overflow-x: auto;
        }

        .chat-message a {
            color: #3182ce;
            text-decoration: underline;
        }

        .chat-message a:hover {
            color: #2c5282;
            text-decoration: none
        }
    </style>
    @script
    <script>
        const el = document.getElementById('messages');

        window.addEventListener('sendmessage', event => {
            setTimeout(() => {
                el.scrollTop = el.scrollHeight
            }, 100)
        });

        // Handle text selection
        document.addEventListener('mouseup', function() {
            const selectedText = window.getSelection().toString().trim();
            const selectedTextIndicator = document.getElementById('selected-text-indicator');
            const selectedTextCharacters = document.getElementById('selected-text-characters');

            if (selectedText) {
                selectedTextCharacters.innerText = selectedText.length;
                selectedTextIndicator.classList.remove('hidden');
                selectedTextIndicator.dataset.selectedText = selectedText;
            } else {
                selectedTextIndicator.classList.add('hidden');
                selectedTextIndicator.dataset.selectedText = '';
            }
        });

        // Add quote to textarea
        document.getElementById('add-quote-button').addEventListener('click', function() {
            const selectedTextIndicator = document.getElementById('selected-text-indicator');
            const selectedText = selectedTextIndicator.dataset.selectedText;
            var textarea = document.querySelector('#chat-input');
            if (selectedText) {
                const quotedText = selectedText.split('\n').map(line => `> ${line}`).join('\n');
                @this.set('question', @this.get('question') + `\n${quotedText}\n`).then(() => {
                    textarea.style.height = "inherit";
                    textarea.style.height = `${textarea.scrollHeight}px`;
                    el.style.paddingBottom = `${textarea.scrollHeight}px`;
                    el.scrollTop = el.scrollHeight;
                    textarea.focus();
                    window.getSelection().removeAllRanges();
                });
                selectedTextIndicator.classList.add('hidden');
                selectedTextIndicator.dataset.selectedText = '';
            }
        });
    </script>
    @endscript
</div>
