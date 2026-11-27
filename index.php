<?php
/**
 * The quiz page. All interaction happens in assets/app.js, which talks to api.php.
 */

declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Country Quiz</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<main class="quiz">
    <header class="quiz-header">
        <h1 data-text="title">Which country is this?</h1>
        <div class="language-switch" role="group" aria-label="Language">
            <button type="button" data-language="sv">Svenska</button>
            <button type="button" data-language="en">English</button>
        </div>
    </header>

    <section class="settings">
        <label>
            <span data-text="area">Area</span>
            <select id="area-select">
                <option value="world" data-text="area-world">World</option>
                <option value="europe" data-text="area-europe">Europe</option>
                <option value="asia" data-text="area-asia">Asia</option>
                <option value="africa" data-text="area-africa">Africa</option>
                <option value="americas" data-text="area-americas">Americas</option>
                <option value="north-america" data-text="area-north-america">North America</option>
                <option value="south-america" data-text="area-south-america">South America</option>
                <option value="oceania" data-text="area-oceania">Oceania</option>
            </select>
        </label>
        <div class="mode-switch" role="group" aria-label="Answer mode">
            <button type="button" data-mode="pick" data-text="mode-pick">Pick from 5</button>
            <button type="button" data-mode="type" data-text="mode-type">Type the name</button>
        </div>
        <label class="checkbox">
            <input type="checkbox" id="surroundings-toggle" checked>
            <span data-text="show-surroundings">Show surrounding countries</span>
        </label>
    </section>

    <section class="score" aria-live="polite">
        <span><span data-text="score">Score</span>: <strong id="score-correct">0</strong> / <span id="score-answered">0</span></span>
        <span><span data-text="streak">Streak</span>: <strong id="score-streak">0</strong></span>
        <span><span data-text="best">Best</span>: <span id="score-best">0</span></span>
        <button type="button" id="reset-button" class="link-button" data-text="reset">Reset</button>
    </section>

    <figure class="map-frame">
        <svg id="map" role="img" aria-label="Country outline" preserveAspectRatio="xMidYMid meet"></svg>
        <div class="zoom-controls" role="group" aria-label="Zoom">
            <button type="button" id="zoom-in-button" data-title="zoom-in" title="Zoom in" aria-label="Zoom in">+</button>
            <button type="button" id="zoom-out-button" data-title="zoom-out" title="Zoom out" aria-label="Zoom out">−</button>
        </div>
    </figure>

    <section class="answer">
        <div id="options" class="options"></div>

        <form id="type-form" class="type-form" autocomplete="off">
            <input id="name-input" list="country-names" data-placeholder="type-placeholder" placeholder="Country name…">
            <datalist id="country-names"></datalist>
            <button type="submit" data-text="guess">Guess</button>
        </form>

        <p id="feedback" class="feedback" aria-live="polite"></p>
        <button type="button" id="next-button" class="next-button" data-text="next" hidden>Next country →</button>
    </section>

    <footer class="credits">
        <span data-text="credits">Map data</span>: <a href="https://www.naturalearthdata.com/">Natural Earth</a> (public domain)
    </footer>
</main>
<script src="assets/app.js"></script>
</body>
</html>
