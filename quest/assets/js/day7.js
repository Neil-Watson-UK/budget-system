/**
 * day7.js
 *
 * Contains the specific game logic for Day 7: The Virtual Meeting Room (Placeholder).
 * This module is designed to be imported and controlled by main2.js.
 *
 * @version 1.0.0
 * @date 2025-07-24
 * @author Gemini Assistant
 */

// Define a global object to hold Day7Game functions immediately
window.Day7Game = window.Day7Game || {};
console.log("window.Day7Game namespace defined and accessible for Virtual Meeting Room.");

(function() { // Wrap the entire script in an IIFE

    // --- Tone.js Audio Setup ---
    let startSynth;
    let winSynth;
    let loseSynth;
    let backgroundMusicSynth;
    let backgroundMusicPart;

    // --- Internal Game State ---
    let canvas;
    let ctx;
    let showMessageBoxRef;
    let displayGameStatusRef;
    let updateGlobalScoreRef;
    let restartCurrentGameRef;
    let getGlobalScoreRef;
    let advanceToNextLevelRef;

    let day7Player = {
        superpower: null,
        level: 7
    };

    let gameRunning = false;

    // --- Game Setup Functions ---

    /**
     * Initializes Tone.js audio instruments.
     */
    async function setupAudio() {
        try {
            if (Tone.context.state !== 'running') {
                await Tone.start();
            }
            console.log("Tone.js audio context started for Day 7.");
        } catch (e) {
            console.error("Failed to start Tone.js audio context for Day 7:", e);
            showMessageBoxRef('Audio Error', 'Could not start game audio. Please interact with the page first.', null, 3000);
        }

        if (!startSynth) {
            startSynth = new Tone.Synth({
                oscillator: { type: "sine" },
                envelope: { attack: 0.05, decay: 0.1, sustain: 0.1, release: 0.2 }
            }).toDestination();
            startSynth.volume.value = -15;
        }
        if (!winSynth) {
            winSynth = new Tone.PolySynth(Tone.Synth, {
                oscillator: { type: "square" },
                envelope: { attack: 0.01, decay: 0.2, sustain: 0.05, release: 0.3 }
            }).toDestination();
            winSynth.volume.value = -10;
        }
        if (!loseSynth) {
            loseSynth = new Tone.NoiseSynth({
                noise: { type: "pink" },
                envelope: { attack: 0.01, decay: 0.5, sustain: 0, release: 1 }
            }).toDestination();
            loseSynth.volume.value = -5;
        }
        if (!backgroundMusicSynth) {
            backgroundMusicSynth = new Tone.PolySynth(Tone.Synth, {
                oscillator: { type: "triangle" },
                envelope: { attack: 0.5, decay: 1, sustain: 0.5, release: 2 }
            }).toDestination();
            backgroundMusicSynth.volume.value = -25;
        }
    }

    /**
     * Placeholder for drawing the game state.
     */
    function drawGame() {
        if (!ctx) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        ctx.fillStyle = '#00353d'; // EPOS Petrol Dark
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        ctx.fillStyle = '#A7D9D3'; // EPOS Mint
        ctx.font = '30px Inter';
        ctx.textAlign = 'center';
        ctx.fillText('Day 7: Virtual Meeting Room', canvas.width / 2, canvas.height / 2 - 50);
        ctx.fillText('Coming Soon!', canvas.width / 2, canvas.height / 2);
        ctx.fillText(`Superpower: ${day7Player.superpower}`, canvas.width / 2, canvas.height / 2 + 50);
    }

    /**
     * Placeholder for the game loop.
     */
    function gameLoop() {
        if (!gameRunning) return;
        drawGame();
        requestAnimationFrame(gameLoop);
    }

    /**
     * Ends the game, displays score, and offers to advance or restart.
     * @param {boolean} won True if the game was won, false otherwise.
     */
    function endGame(won) {
        gameRunning = false;
        removeInputListeners();

        // Stop background music
        if (backgroundMusicPart) {
            backgroundMusicPart.stop();
            backgroundMusicPart.dispose();
            backgroundMusicPart = null;
        }
        Tone.Transport.stop();
        Tone.Transport.cancel();

        let messageTitle;
        let messageContent;
        const prizeCode = 'EPOS 700 ANC'; // Example prize code

        if (won) {
            messageTitle = 'Day 7 Complete!';
            messageContent = `You successfully navigated the Virtual Meeting Room! Your score: ${getGlobalScoreRef()}.
                <br><br><span style="font-size: 28px;">The code to enter today's prize draw is: <b>${prizeCode}</b></span>`;
            if (winSynth && Tone.context.state === 'running') winSynth.triggerAttackRelease(["C5", "E5", "G5"], "2n");
        } else {
            messageTitle = 'Challenge Failed!';
            messageContent = `You couldn't connect in the Virtual Meeting Room. Your score: ${getGlobalScoreRef()}.
                <br><br><span style="font-size: 28px;">The code to enter today's prize draw is: <b>${prizeCode}</b></span>`;
            if (loseSynth && Tone.context.state === 'running') loseSynth.triggerAttackRelease("C2", "8n");
        }

        showMessageBoxRef(messageTitle, messageContent, () => {
            if (won) {
                advanceToNextLevelRef(); // Advance to next day
            } else {
                restartCurrentGameRef(); // Offer to restart current day
            }
        }, 0, true); // isHtmlContent = true
    }

    // --- Input Handling (Placeholder) ---
    function addInputListeners() {
        // No specific input listeners for this placeholder
        console.log("Day 7 input listeners added (placeholder).");
    }

    function removeInputListeners() {
        // No specific input listeners for this placeholder
        console.log("Day 7 input listeners removed (placeholder).");
    }

    // --- Exported Functions for main2.js ---

    /**
     * Initializes the Day 7 game module. This is called by main2.js when Day 7 is selected.
     * Sets up canvas, audio, and prepares game state.
     * @param {HTMLCanvasElement} gameCanvasRef The canvas element reference.
     * @param {CanvasRenderingContext2D} context The 2D rendering context of the canvas.
     * @param {function} showModalMessageBoxFunc Reference to main2.js's showMessageBox (modal).
     * @param {function} displayGameStatusFunc Reference to main2.js's displayGameStatus (non-modal).
     * @param {function} updateScoreFunction Reference to main2.js's updateGlobalScore.
     * @param {function} restartGameFunction Reference to main2.js's restartCurrentGame.
     * @param {function} getScoreFunction Reference to main2.js's getGlobalScore.
     * @param {HTMLElement} tokensDisplayRef (unused for Day 7, passed for consistency)
     * @param {HTMLElement} sackCapacityRef (unused for Day 7, passed for consistency)
     * @param {HTMLElement} changeSackButtonRef (unused for Day 7, passed for consistency)
     * @param {HTMLElement} startButtonRef (unused for Day 7, passed for consistency)
     * @param {HTMLElement} pauseButtonRef (unused for Day 7, passed for consistency)
     * @param {HTMLElement} restartButtonRef (unused for Day 7, passed for consistency)
     * @param {object} playerState Object containing player's superpower.
     * @param {number} currentDayLevel The current day level from main2.js.
     */
    window.Day7Game.initDay7Game = async function(gameCanvasRef, context, showModalMessageBoxFunc, displayGameStatusFunc, updateScoreFunction, restartGameFunction, getScoreFunction, tokensDisplayRef, sackCapacityRef, changeSackButtonRef, startButtonRef, pauseButtonRef, restartButtonRef, playerState, currentDayLevel) {
        console.log("Day 7 Virtual Meeting Room Initializing...");
        canvas = gameCanvasRef;
        ctx = context;
        showMessageBoxRef = showModalMessageBoxFunc;
        displayGameStatusRef = displayGameStatusFunc;
        updateGlobalScoreRef = updateScoreFunction;
        restartCurrentGameRef = restartGameFunction;
        getGlobalScoreRef = getScoreFunction;
        advanceToNextLevelRef = window.advanceToNextLevel; // Get global advance function

        // Hide UI elements specific to other days if they exist
        if (tokensDisplayRef && tokensDisplayRef.parentElement) tokensDisplayRef.parentElement.classList.add('hidden');
        if (sackCapacityRef && sackCapacityRef.parentElement) sackCapacityRef.parentElement.classList.add('hidden');
        if (changeSackButtonRef) changeSackButtonRef.classList.add('hidden');
        if (startButtonRef) startButtonRef.classList.remove('hidden'); // Show start/pause/restart buttons
        if (pauseButtonRef) pauseButtonRef.classList.remove('hidden');
        if (restartButtonRef) restartButtonRef.classList.remove('hidden');

        day7Player.superpower = playerState.superpower;
        day7Player.level = currentDayLevel;

        // Ensure canvas pixel dimensions match its current CSS size for clear drawing
        if (canvas && canvas.parentElement) {
            canvas.width = canvas.parentElement.clientWidth;
            canvas.height = canvas.parentElement.clientHeight;
        }

        await setupAudio(); // Initialize Tone.js instruments

        // Add a resize listener to handle window resizing dynamically
        window.addEventListener('resize', window.Day7Game.onWindowResize);

        window.Day7Game.resetDay7Game(); // Call reset to set initial state
        console.log("Day 7 Virtual Meeting Room Initialized with superpower:", day7Player.superpower);
    };

    /**
     * Resets the entire game state for Day 7, preparing for a new game.
     */
    window.Day7Game.resetDay7Game = function() {
        gameRunning = false;
        updateGlobalScoreRef(0); // Reset global score display

        removeInputListeners();

        // Stop and dispose background music part if it exists
        if (backgroundMusicPart) {
            backgroundMusicPart.stop();
            backgroundMusicPart.dispose();
            backgroundMusicPart = null;
        }
        Tone.Transport.stop();
        Tone.Transport.cancel();

        drawGame(); // Draw initial state

        console.log("Day 7 Virtual Meeting Room Reset.");
    };

    /**
     * Starts the Day 7 game.
     */
    window.Day7Game.startGame = function() {
        if (gameRunning) return;
        gameRunning = true;
        addInputListeners();
        gameLoop(); // Start the placeholder game loop

        showMessageBoxRef('Day 7: Virtual Meeting Room', `Welcome to the Virtual Meeting Room!
            <br><br>This challenge is still under development.
            <br><br>For now, you can click "Complete Day" to advance or "Restart" to try again!
            <br><br>Your superpower (${day7Player.superpower}) is active!`, () => {
            // Start background music (placeholder)
            if (backgroundMusicSynth && !backgroundMusicPart && Tone.context.state === 'running') {
                const notes = ["C4", "G3", "A3", "F3"];
                let currentNoteIndex = 0;
                backgroundMusicPart = new Tone.Loop(time => {
                    backgroundMusicSynth.triggerAttackRelease(notes[currentNoteIndex % notes.length], "1n", time);
                    currentNoteIndex++;
                }, "1n").start(0);
            }
            Tone.Transport.start();
        }, 0, true); // isHtmlContent = true

        // For demonstration, automatically "win" after a few seconds
        setTimeout(() => {
            if (gameRunning) { // Only end if game is still running
                updateGlobalScoreRef(500); // Give some placeholder score
                endGame(true); // Simulate winning the day
            }
        }, 5000); // Auto-complete after 5 seconds for testing

        console.log("Day 7 Virtual Meeting Room Started.");
    };

    /**
     * Pauses the Day 7 game.
     */
    window.Day7Game.pauseGame = function() {
        if (!gameRunning) return;
        gameRunning = false;
        removeInputListeners();

        if (backgroundMusicPart) backgroundMusicPart.stop();
        Tone.Transport.pause();

        showMessageBoxRef('Game Paused', 'Virtual Meeting Room is on hold. Click OK to resume.', () => {
            gameRunning = true;
            addInputListeners();
            gameLoop();
            if (backgroundMusicPart) backgroundMusicPart.start();
            Tone.Transport.start();
        }, 0, true); // isHtmlContent = true
        console.log("Day 7 Virtual Meeting Room Paused.");
    };

    /**
     * Restarts the Day 7 game.
     */
    window.Day7Game.restartGame = function() {
        gameRunning = false;
        removeInputListeners();

        // Stop and dispose background music part if it exists
        if (backgroundMusicPart) {
            backgroundMusicPart.stop();
            backgroundMusicPart.dispose();
            backgroundMusicPart = null;
        }
        Tone.Transport.stop();
        Tone.Transport.cancel();

        showMessageBoxRef('Restarting Day 7', 'Starting Virtual Meeting Room from the beginning.', () => {
            restartCurrentGameRef(); // Call main2.js's restart function
        }, 0, true);
        console.log("Day 7 Virtual Meeting Room Restart requested.");
    };

    /**
     * Handles window resize events to adjust canvas dimensions and redraw the game.
     */
    window.Day7Game.onWindowResize = function() {
        if (canvas && canvas.parentElement) {
            canvas.width = canvas.parentElement.clientWidth;
            canvas.height = canvas.parentElement.clientHeight;
            drawGame(); // Redraw content on resize
        }
    };

    console.log("Day7.js script loaded.");

})(); // End of IIFE
