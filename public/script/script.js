/* FAQ Chatbot — client UI logic */
(() => {
    'use strict';

    const SUGGESTIONS = [
        'What are your opening hours?',
        'How long does shipping take?',
        'What is your refund policy?',
        'Help / commands'
    ];
    //element camelCase 
    const messagesEl = document.getElementById('messages');
    const welcomeEl   = document.getElementById('welcome');
    const typingEl    = document.getElementById('typing');
    const formEl      = document.getElementById('composer');
    const inputEl     = document.getElementById('input');
    const sendBtn     = document.getElementById('send');
    

    // Demo mode flag - set to false to use real API
    const DEMO_MODE = true

    // Fake responses for demo mode
    const FAKE_RESPONSES = [
        'I received your question.',
        'Thanks for asking! That\'s a great question.',
        'Let me help you with that.',
        'I\'m here to assist you.'
    ];

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    //const d=date h=hours m=minutes
    function nowTime() {
        const d = new Date();
        const h = String(d.getHours()).padStart(2, '0');
        const m = String(d.getMinutes()).padStart(2, '0');
        return `${h}:${m}`;
    }

    function hideWelcome() {
        if (welcomeEl && !welcomeEl.classList.contains('hidden')) {
            welcomeEl.classList.add('hidden');
        }
    }

    function addMessage(role, text) {
        hideWelcome();

        const wrap = document.createElement('div');
        wrap.className = `message ${role}`;

        const avatar = document.createElement('div');
        avatar.className = 'message-avatar';
        avatar.innerHTML = role === 'user' ? '🧑' : ' <image class="bot-avatar" src="assets/aria-small.gif">';

        const bubble = document.createElement('div');
        bubble.className = 'message-bubble';
        bubble.innerHTML = escapeHtml(text).replace(/\n/g, '<br>');

        const time = document.createElement('div');
        time.className = 'message-time';
        time.textContent = nowTime();

        wrap.appendChild(avatar);
        wrap.appendChild(bubble);
        wrap.appendChild(time);

        // Insert messages before typing indicator so typing appears at bottom
        if (typingEl && typingEl.classList.contains('show')) {
            messagesEl.insertBefore(wrap,typingEl);
        } else {
            messagesEl.append(wrap, typingEl);
        }
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function setTyping(on) {
        if (!typingEl) return;
        typingEl.classList.toggle('show', !!on);
        if (on) messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function disableInput(disabled) {
        inputEl.disabled = disabled;
        sendBtn.disabled = disabled;
        inputEl.focus();
    }

    function getFakeResponse() {
        const idx = Math.floor(Math.random() * FAKE_RESPONSES.length);
        return FAKE_RESPONSES[idx];
    }
//test
    async function askBot(message) {
        if (DEMO_MODE) {
            // Simulate delay and return fake response
            await new Promise(resolve => setTimeout(resolve, 3000));
            return '[Demo] ' + getFakeResponse();
        }
        try {
            const res = await fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message })
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            return data.answer || data.error || '(no answer)';
        } catch (err) {
            return `Sorry, something went wrong: ${err.message}`;
        }
    }

    //clear chat after interval
    let clearTimer;
    async function chatClearTimer(){
            clearTimeout(clearTimer);
            clearTimer = setTimeout(
                ()=>{
                    messagesEl.innerHTML= '';
                    addMessage('bot','hello, I am Aria, How can I help you?');
                    console.log('interval 10 sec');
                },60*1000 //10 sec
            );
        
    }

    async function send(text) {
        const trimmed = String(text || '').trim();
        if (!trimmed) return;

        addMessage('user', trimmed);
        disableInput(true);
        setTyping(true);

        const answer = await askBot(trimmed);

        setTyping(false);
        addMessage('bot', answer);
        disableInput(false);
        chatClearTimer();
    }

    
    // Suggestion chips
    document.querySelectorAll('.suggestion-chip').forEach(chip => {
        chip.addEventListener('click', () => send(chip.dataset.text || chip.textContent));
    });

    // Form submit (button + Enter)
    formEl.addEventListener('submit', e => {
        e.preventDefault();
        const v = inputEl.value;
        inputEl.value = '';
        send(v);
    });
    inputEl.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' ) {
            const v = inputEl.value;
            inputEl.value = '';
            send(v);
        }
        });

    // Auto-grow textarea
    inputEl.addEventListener('input', () => {
        inputEl.style.height = 'auto';
        inputEl.style.height = Math.min(inputEl.scrollHeight, 120) + 'px';
    });
    inputEl.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            formEl.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        }
    });

    // Friendly initial state
    if (welcomeEl) welcomeEl.classList.remove('hidden');
    inputEl.focus();

    // Theme toggle functionality
    // Cycle: default (light) → light-grey → green-accent → default
    const themeToggle = document.getElementById('theme-toggle');
    const themes = ['', 'light-grey', 'green-accent'];
    let themeIndex = 0;
    const currentTheme = document.documentElement.getAttribute('data-theme');
    if (currentTheme === 'light-grey') themeIndex = 1;
    else if (currentTheme === 'green-accent') themeIndex = 2;
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            themeIndex = (themeIndex + 1) % themes.length;
            const nextTheme = themes[themeIndex];
            if (nextTheme) {
                document.documentElement.setAttribute('data-theme', nextTheme);
            } else {
                document.documentElement.removeAttribute('data-theme');
            }
        });
    }
})();