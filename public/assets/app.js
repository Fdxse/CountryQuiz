/**
 * Country Quiz front end. Plain JavaScript, no libraries.
 * Draws the country outline as SVG and talks to api.php for questions and answers.
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------ */
    /* Texts in both languages                                             */
    /* ------------------------------------------------------------------ */

    const TEXTS = {
        en: {
            'title': 'Which country is this?',
            'area': 'Area',
            'area-world': 'World',
            'area-europe': 'Europe',
            'area-asia': 'Asia',
            'area-africa': 'Africa',
            'area-americas': 'Americas',
            'area-north-america': 'North America',
            'area-south-america': 'South America',
            'area-oceania': 'Oceania',
            'mode-pick': 'Pick from 5',
            'mode-type': 'Type the name',
            'show-surroundings': 'Show surrounding countries',
            'score': 'Score',
            'streak': 'Streak',
            'best': 'Best',
            'reset': 'Reset',
            'type-placeholder': 'Country name…',
            'guess': 'Guess',
            'next': 'Next country →',
            'credits': 'Map data',
            'correct': 'Correct! It is {name}.',
            'wrong': 'Not quite. It is {name}.',
            'wrong-guess': 'That was {guess}. The right answer is {name}.',
            'other-language': 'In Swedish: {name}',
            'load-error': 'Could not load a question. Try again.',
        },
        sv: {
            'title': 'Vilket land är det här?',
            'area': 'Område',
            'area-world': 'Hela världen',
            'area-europe': 'Europa',
            'area-asia': 'Asien',
            'area-africa': 'Afrika',
            'area-americas': 'Amerika',
            'area-north-america': 'Nordamerika',
            'area-south-america': 'Sydamerika',
            'area-oceania': 'Oceanien',
            'mode-pick': 'Välj bland 5',
            'mode-type': 'Skriv namnet',
            'show-surroundings': 'Visa omgivande länder',
            'score': 'Poäng',
            'streak': 'Svit',
            'best': 'Bästa',
            'reset': 'Nollställ',
            'type-placeholder': 'Landets namn…',
            'guess': 'Gissa',
            'next': 'Nästa land →',
            'credits': 'Kartdata',
            'correct': 'Rätt! Det är {name}.',
            'wrong': 'Inte riktigt. Det är {name}.',
            'wrong-guess': 'Det där var {guess}. Rätt svar är {name}.',
            'other-language': 'På engelska: {name}',
            'load-error': 'Kunde inte ladda en fråga. Försök igen.',
        },
    };

    /* ------------------------------------------------------------------ */
    /* State                                                               */
    /* ------------------------------------------------------------------ */

    const settings = {
        language: readSetting('language', 'sv'),
        mode: readSetting('mode', 'pick'),
        area: readSetting('area', 'world'),
        showSurroundings: readSetting('showSurroundings', 'true') === 'true',
    };

    /** The question currently on screen (as returned by api.php). */
    let currentQuestion = null;
    /** True once the current question has been answered. */
    let isAnswered = false;

    /* ------------------------------------------------------------------ */
    /* Elements                                                            */
    /* ------------------------------------------------------------------ */

    const mapElement = document.getElementById('map');
    const areaSelect = document.getElementById('area-select');
    const surroundingsToggle = document.getElementById('surroundings-toggle');
    const optionsContainer = document.getElementById('options');
    const typeForm = document.getElementById('type-form');
    const nameInput = document.getElementById('name-input');
    const namesDatalist = document.getElementById('country-names');
    const feedbackElement = document.getElementById('feedback');
    const nextButton = document.getElementById('next-button');
    const resetButton = document.getElementById('reset-button');

    const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

    /* ------------------------------------------------------------------ */
    /* Small helpers                                                       */
    /* ------------------------------------------------------------------ */

    /** localStorage can throw (private mode, blocked storage), so it is wrapped. */
    function readSetting(key, fallbackValue) {
        try {
            return window.localStorage.getItem('countryQuiz.' + key) || fallbackValue;
        } catch (error) {
            return fallbackValue;
        }
    }

    function saveSetting(key, value) {
        try {
            window.localStorage.setItem('countryQuiz.' + key, String(value));
        } catch (error) {
            // Not important: the setting just won't be remembered.
        }
    }

    /** Looks up a text in the current language and fills in {placeholders}. */
    function translate(key, replacements) {
        let text = TEXTS[settings.language][key] || key;
        Object.keys(replacements || {}).forEach(function (placeholder) {
            text = text.replace('{' + placeholder + '}', replacements[placeholder]);
        });
        return text;
    }

    async function callApi(action, options) {
        const query = new URLSearchParams({ action: action, lang: settings.language, area: settings.area });
        const response = await fetch('api.php?' + query.toString(), Object.assign({
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
        }, options || {}));
        if (!response.ok) {
            throw new Error('API error ' + response.status);
        }
        return response.json();
    }

    /* ------------------------------------------------------------------ */
    /* Drawing the map                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Simple equirectangular projection. Longitudes are scaled by cos(latitude at the view
     * center) so countries far from the equator are not stretched sideways.
     */
    function createProjection(view) {
        const [minLongitude, minLatitude, maxLongitude, maxLatitude] = view;
        const centerLatitude = (minLatitude + maxLatitude) / 2;
        const longitudeScale = Math.max(0.2, Math.cos(centerLatitude * Math.PI / 180));
        return {
            width: (maxLongitude - minLongitude) * longitudeScale,
            height: maxLatitude - minLatitude,
            project: function (point) {
                return [(point[0] - minLongitude) * longitudeScale, maxLatitude - point[1]];
            },
        };
    }

    /** Turns a list of rings into an SVG path "d" attribute. */
    function ringsToPath(rings, projection) {
        return rings.map(function (ring) {
            return ring.map(function (point, index) {
                const [x, y] = projection.project(point);
                return (index === 0 ? 'M' : 'L') + x.toFixed(3) + ' ' + y.toFixed(3);
            }).join('') + 'Z';
        }).join('');
    }

    function createSvgElement(tagName, attributes) {
        const element = document.createElementNS(SVG_NAMESPACE, tagName);
        Object.keys(attributes).forEach(function (name) {
            element.setAttribute(name, attributes[name]);
        });
        return element;
    }

    function drawQuestion(question) {
        const projection = createProjection(question.view);
        mapElement.innerHTML = '';
        mapElement.setAttribute('viewBox', '0 0 ' + projection.width.toFixed(3) + ' ' + projection.height.toFixed(3));
        // Let the map box follow the shape of the view, within reasonable limits for phones and desktops.
        const aspectRatio = Math.min(2.2, Math.max(0.75, projection.width / projection.height));
        mapElement.style.setProperty('--map-aspect', aspectRatio.toFixed(3));

        // Sea
        mapElement.appendChild(createSvgElement('rect', {
            class: 'sea', x: 0, y: 0, width: projection.width, height: projection.height,
        }));

        // Surrounding countries (hidden with CSS when the player turns them off)
        const surroundingsGroup = createSvgElement('g', { class: 'surroundings' });
        question.surroundings.forEach(function (country) {
            surroundingsGroup.appendChild(createSvgElement('path', {
                class: 'country', 'data-code': country.code, d: ringsToPath(country.rings, projection),
            }));
        });
        mapElement.appendChild(surroundingsGroup);

        // The country to guess
        mapElement.appendChild(createSvgElement('path', {
            class: 'target', d: ringsToPath(question.target.rings, projection),
        }));

        // Very small countries get a circle around them so they can be found.
        const targetBox = mapElement.lastChild.getBBox();
        const targetSize = Math.max(targetBox.width, targetBox.height);
        if (targetSize < projection.width * 0.04) {
            mapElement.appendChild(createSvgElement('circle', {
                class: 'small-marker',
                cx: targetBox.x + targetBox.width / 2,
                cy: targetBox.y + targetBox.height / 2,
                r: projection.width * 0.04,
            }));
        }

        applySurroundingsVisibility();
    }

    function applySurroundingsVisibility() {
        mapElement.classList.toggle('hide-surroundings', !settings.showSurroundings);
    }

    /* ------------------------------------------------------------------ */
    /* Questions and answers                                               */
    /* ------------------------------------------------------------------ */

    async function loadQuestion() {
        feedbackElement.textContent = '';
        feedbackElement.className = 'feedback';
        nextButton.hidden = true;
        mapElement.classList.remove('answered-correct', 'answered-wrong');
        isAnswered = false;
        try {
            currentQuestion = await callApi('question');
        } catch (error) {
            feedbackElement.textContent = translate('load-error');
            feedbackElement.classList.add('wrong');
            nextButton.hidden = false;
            return;
        }
        drawQuestion(currentQuestion);
        renderOptions(currentQuestion.options);
        renderScore(currentQuestion.score);
        nameInput.value = '';
        nameInput.disabled = false;
        if (settings.mode === 'type') {
            nameInput.focus();
        }
    }

    function renderOptions(options) {
        optionsContainer.innerHTML = '';
        options.forEach(function (option, index) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'option';
            button.dataset.code = option.code;
            button.textContent = (index + 1) + '. ' + option.name;
            button.addEventListener('click', function () {
                submitAnswer({ code: option.code });
            });
            optionsContainer.appendChild(button);
        });
    }

    function renderScore(score) {
        document.getElementById('score-correct').textContent = score.correct;
        document.getElementById('score-answered').textContent = score.answered;
        document.getElementById('score-streak').textContent = score.streak;
        document.getElementById('score-best').textContent = score.bestStreak;
    }

    async function submitAnswer(answer) {
        if (isAnswered || !currentQuestion) {
            return;
        }
        isAnswered = true;
        let result;
        try {
            result = await callApi('answer', { method: 'POST', body: JSON.stringify(answer) });
        } catch (error) {
            isAnswered = false;
            feedbackElement.textContent = translate('load-error');
            return;
        }
        showResult(result);
    }

    function showResult(result) {
        // Mark the option buttons
        optionsContainer.querySelectorAll('.option').forEach(function (button) {
            button.disabled = true;
            if (button.dataset.code === result.correctCode) {
                button.classList.add('correct');
            } else if (button.dataset.code === result.guessedCode) {
                button.classList.add('wrong');
            }
        });
        nameInput.disabled = true;

        // Highlight the wrongly guessed country on the map if it is visible
        mapElement.classList.add(result.correct ? 'answered-correct' : 'answered-wrong');
        if (!result.correct && result.guessedCode) {
            const guessedPath = mapElement.querySelector('.country[data-code="' + result.guessedCode + '"]');
            if (guessedPath) {
                guessedPath.classList.add('guessed');
            }
        }

        // Text feedback
        let message;
        if (result.correct) {
            message = translate('correct', { name: result.correctName });
        } else if (result.guessedCode && settings.mode === 'type') {
            message = translate('wrong-guess', { guess: nameInput.value, name: result.correctName });
        } else {
            message = translate('wrong', { name: result.correctName });
        }
        if (result.otherName !== result.correctName) {
            message += ' (' + translate('other-language', { name: result.otherName }) + ')';
        }
        feedbackElement.textContent = message;
        feedbackElement.className = 'feedback ' + (result.correct ? 'correct' : 'wrong');

        renderScore(result.score);
        nextButton.hidden = false;
        nextButton.focus();
    }

    /* ------------------------------------------------------------------ */
    /* Settings                                                            */
    /* ------------------------------------------------------------------ */

    /** Updates every element with data-text / data-placeholder to the current language. */
    function applyLanguage() {
        document.documentElement.lang = settings.language;
        document.querySelectorAll('[data-text]').forEach(function (element) {
            element.textContent = translate(element.dataset.text);
        });
        document.querySelectorAll('[data-placeholder]').forEach(function (element) {
            element.placeholder = translate(element.dataset.placeholder);
        });
        document.querySelectorAll('[data-language]').forEach(function (button) {
            button.classList.toggle('active', button.dataset.language === settings.language);
        });
        document.title = translate('title');
    }

    function applyMode() {
        document.body.dataset.mode = settings.mode;
        document.querySelectorAll('[data-mode]').forEach(function (button) {
            button.classList.toggle('active', button.dataset.mode === settings.mode);
        });
    }

    async function loadCountryNames() {
        try {
            const result = await callApi('names');
            namesDatalist.innerHTML = '';
            result.names.forEach(function (name) {
                const option = document.createElement('option');
                option.value = name;
                namesDatalist.appendChild(option);
            });
        } catch (error) {
            // Autocomplete is a nice-to-have; typing still works without it.
        }
    }

    document.querySelectorAll('[data-language]').forEach(function (button) {
        button.addEventListener('click', function () {
            settings.language = button.dataset.language;
            saveSetting('language', settings.language);
            applyLanguage();
            loadCountryNames();
            // Option names come from the server in the chosen language, so fetch a new question.
            loadQuestion();
        });
    });

    document.querySelectorAll('[data-mode]').forEach(function (button) {
        button.addEventListener('click', function () {
            settings.mode = button.dataset.mode;
            saveSetting('mode', settings.mode);
            applyMode();
            if (settings.mode === 'type' && !isAnswered) {
                nameInput.focus();
            }
        });
    });

    areaSelect.addEventListener('change', function () {
        settings.area = areaSelect.value;
        saveSetting('area', settings.area);
        loadQuestion();
    });

    surroundingsToggle.addEventListener('change', function () {
        settings.showSurroundings = surroundingsToggle.checked;
        saveSetting('showSurroundings', settings.showSurroundings);
        applySurroundingsVisibility();
    });

    typeForm.addEventListener('submit', function (event) {
        event.preventDefault();
        const typedName = nameInput.value.trim();
        if (typedName !== '') {
            submitAnswer({ name: typedName });
        }
    });

    nextButton.addEventListener('click', loadQuestion);

    resetButton.addEventListener('click', async function () {
        const result = await callApi('reset', { method: 'POST', body: '{}' });
        renderScore(result.score);
    });

    // Keyboard shortcuts in pick mode: 1–5 picks an option, Enter goes to the next country.
    document.addEventListener('keydown', function (event) {
        if (event.target === nameInput || event.target === areaSelect) {
            return;
        }
        const optionNumber = Number(event.key);
        if (settings.mode === 'pick' && !isAnswered && optionNumber >= 1 && optionNumber <= 5) {
            const optionButton = optionsContainer.querySelectorAll('.option')[optionNumber - 1];
            if (optionButton) {
                optionButton.click();
            }
        }
    });

    /* ------------------------------------------------------------------ */
    /* Start                                                               */
    /* ------------------------------------------------------------------ */

    areaSelect.value = settings.area;
    if (!areaSelect.value) {
        areaSelect.value = settings.area = 'world';
    }
    surroundingsToggle.checked = settings.showSurroundings;
    applyLanguage();
    applyMode();
    loadCountryNames();
    loadQuestion();
})();
