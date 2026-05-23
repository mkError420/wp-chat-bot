(function () {
    var toggle = document.getElementById('chatbot-toggle');
    var closeButton = document.getElementById('chatbot-close');
    var panel = document.querySelector('.chatbot-panel');
    var messages = document.getElementById('chatbot-messages');
    var form = document.getElementById('chatbot-form');
    var input = document.getElementById('chatbot-input');

    if (!toggle || !panel || !messages || !form || !input) {
        return;
    }

    function setPanel(open) {
        panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.body.classList.toggle('chatbot-open', open);
    }

    function appendMessage(text, type) {
        var message = document.createElement('div');
        message.className = 'chatbot-message ' + type;
        message.textContent = text;
        messages.appendChild(message);
        messages.scrollTop = messages.scrollHeight;
    }

    function sendQuestion(question) {
        appendMessage(question, 'user');
        appendMessage('Looking for the best answer on this site...', 'bot');

        fetch(chatbotSettings.restUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json;charset=utf-8',
                'X-WP-Nonce': chatbotSettings.nonce,
            },
            body: JSON.stringify({ message: question }),
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                var lastBot = messages.querySelector('.chatbot-message.bot:last-child');
                if (lastBot && lastBot.textContent === 'Looking for the best answer on this site...') {
                    lastBot.remove();
                }

                if (data && data.answer) {
                    appendMessage(data.answer, 'bot');
                    if (data.source) {
                        appendMessage('Source: ' + data.source, 'source');
                    }
                } else if (data && data.message) {
                    appendMessage(data.message, 'bot');
                } else {
                    appendMessage('Sorry, I could not get an answer right now. Please try again.', 'bot');
                }
            })
            .catch(function () {
                var lastBot = messages.querySelector('.chatbot-message.bot:last-child');
                if (lastBot && lastBot.textContent === 'Looking for the best answer on this site...') {
                    lastBot.remove();
                }
                appendMessage('There was a connection issue. Please try again in a moment.', 'bot');
            });
    }

    toggle.addEventListener('click', function () {
        var open = panel.getAttribute('aria-hidden') === 'true';
        setPanel(open);
    });

    closeButton.addEventListener('click', function () {
        setPanel(false);
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var question = input.value.trim();
        if (!question) {
            return;
        }
        input.value = '';
        sendQuestion(question);
    });
})();
