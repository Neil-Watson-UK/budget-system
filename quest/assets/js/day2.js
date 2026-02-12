/**
 * day2.js
 *
 * Contains the specific game logic for Day 2: The Harmony Balancing Game.
 * This module is designed to be imported and controlled by main2.js.
 *
 * @version 1.9.6
 * @date 2025-07-23
 * @author Gemini Assistant
 */

// Define a global object to hold Day2Game functions immediately
window.Day2Game = window.Day2Game || {};
console.log("window.Day2Game namespace defined and accessible.");

(function() { // Wrap the entire script in an IIFE

// --- Define Colors for Drawing ---
const COLORS = {
    goldAccent: '#FFD700', // Gold color for accents, like target lines
    eposMint: '#A7D9D3', // EPOS Mint for filled bar part
    eposPetrolDark: '#00353d', // Darker petrol background
    eposPetrolLight: '#3A5E65', // Lighter petrol for empty bar part
    clickHighlight: '#FFFFFF', // White for click feedback
    perfectHarmonyGlow: '#00FF00', // Bright green for perfect harmony glow
    disharmonyGlow: '#FF0000', // Bright red for disharmony background
};

// Helper to convert hex color to RGB object
function hexToRgb(hex) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return { r, g, b };
}

// Helper for linear interpolation of color components
function lerpColor(c1, c2, factor) {
    const r = Math.round(c1.r + (c2.r - c1.r) * factor);
    const g = Math.round(c1.g + (c2.g - c1.b) * factor); // Fixed typo: c2.b - c1.b
    const b = Math.round(c1.b + (c2.b - c1.b) * factor);
    return `rgb(${r},${g},${b})`;
}

// --- Tone.js Audio Setup ---
let melodySynth;       // For the Jingle Bells melody
let clickSynth;        // For the individual bar click feedback
let melodySequence;    // The Tone.Sequence for Jingle Bells

// Jingle Bells Melody (simplified for looping background)
// Each object contains a note and its duration
const JINGLE_BELLS_MELODY_EVENTS = [
    { note: 'E4', duration: '8n' }, { note: 'E4', duration: '8n' }, { note: 'E4', duration: '4n' }, // Jingle bells
    { note: 'E4', duration: '8n' }, { note: 'E4', duration: '8n' }, { note: 'E4', duration: '4n' }, // Jingle bells
    { note: 'E4', duration: '8n' }, { note: 'G4', duration: '8n' }, { note: 'C4', duration: '8n' }, { note: 'D4', duration: '8n' }, { note: 'E4', duration: '2n' }, // Jingle all the way
    { note: 'F4', duration: '8n' }, { note: 'F4', duration: '8n' }, { note: 'F4', duration: '8n' }, { note: 'F4', duration: '8n' }, // Oh what fun
    { note: 'F4', duration: '8n' }, { note: 'E4', duration: '8n' }, { note: 'E4', duration: '8n' }, { note: 'E4', duration: '8n' }, { note: 'E4', duration: '16n' }, { note: 'D4', duration: '16n' }, // it is to ride
    { note: 'D4', duration: '8n' }, { note: 'D4', duration: '8n' }, { note: 'E4', duration: '8n' }, { note: 'D4', duration: '4n' }, { note: 'G4', duration: '2n' } // In a one-horse open sleigh, hey!
];

// Frequencies for individual bar clicks (higher octave for distinct feedback)
const BAR_CLICK_NOTES = ['C5', 'E5', 'G5']; // Adjusted for 3 bars

// --- Internal Game State for Day 2 (Harmony Balancing Game) ---
let canvas; // Now a local variable within the IIFE
let ctx;    // Now a local variable within the IIFE
let showMessageBoxRef;
let displayGameStatusRef;
let updateGlobalScoreRef;
let restartCurrentGameRef;
let getGlobalScoreRef;

let gameRunning = false;
let animationFrameId;

let day2Player = {
    superpower: null,
    level: 2 // This module is specifically for Day 2
};

// Harmony Balancing Game specific state variables
let harmonyScore = 0;
const NUM_SOUND_LEVELS = 3; // Number of bars to balance (changed back to 3)
let soundLevels = []; // Current levels of each bar (0-100)
let targetLevels = []; // Target levels for each bar (0-100)
const LEVEL_MAX = 100; // Max value for a bar
const LEVEL_MIN = 0;
const LEVEL_ADJUST_CLICK_AMOUNT = 10; // How much a click/key press changes a level
const FALL_RATE = 12; // Units per second that levels fall (reduced from 15 for easier play)

let gameDuration = 10; // seconds - Changed to 10 seconds
let timeRemaining = gameDuration;
let lastFrameTime = 0;

let clickedBarIndex = -1; // Index of the bar that was just clicked/tapped
let clickFlashEndTime = 0; // Time when click highlight should end (ms)
const CLICK_FLASH_DURATION = 200; // How long the click highlight lasts (ms)

// --- Game Configuration for Day 2 (can be adjusted by superpower) ---
let gameConfig = {
    IMPACT: {
        harmonyThreshold: 10, // Levels need to be within 10 of target for points
        scorePerSecondBase: 15, // Increased from 10
        timeLimit: 10 // Changed to 10 seconds
    },
    ADAPT: {
        harmonyThreshold: 15, // More forgiving
        scorePerSecondBase: 10, // Increased from 6
        timeLimit: 10 // Changed to 10 seconds
    },
    EXPAND: {
        harmonyThreshold: 8, // Stricter
        scorePerSecondBase: 20, // Increased from 14
        timeLimit: 10 // Changed to 10 seconds
    }
};

// --- Utility Functions for Harmony Balancing Game ---
/**
 * Generates random target levels for the harmony bars.
 */
function generateTargetLevels() {
    targetLevels = [];
    for (let i = 0; i < NUM_SOUND_LEVELS; i++) {
        // Ensure targets are not too close to edges for easier play
        targetLevels.push(Math.floor(Math.random() * (LEVEL_MAX - LEVEL_MIN - 40) + 20)); // Targets between 20 and 80
    }
    console.log("New Target Levels:", targetLevels);
}

/**
 * Calculates the overall "harmony" based on how close current levels are to target levels.
 * Returns a value from 0 (no harmony) to 1 (perfect harmony).
 * A value of 1 means all bars are exactly at their target.
 */
function calculateHarmonyFactor() {
    let totalNormalizedDifference = 0;
    for (let i = 0; i < NUM_SOUND_LEVELS; i++) {
        const diff = Math.abs(soundLevels[i] - targetLevels[i]);
        // Normalize difference by max possible difference for a single bar
        const normalizedDiff = diff / LEVEL_MAX;
        totalNormalizedDifference += normalizedDiff;
    }
    // Average normalized difference across all bars
    const averageNormalizedDifference = totalNormalizedDifference / NUM_SOUND_LEVELS;

    // Harmony factor: 1 when average difference is 0, decreases as difference increases.
    // Applying Math.pow(..., 2) to make being very close much more rewarding.
    return Math.max(0, Math.pow(1 - averageNormalizedDifference, 2));
}

/**
 * Checks if the current sound levels are within the harmony threshold of the target levels.
 * @returns {boolean} True if in harmony, false otherwise.
 */
function isInHarmony() {
    for (let i = 0; i < NUM_SOUND_LEVELS; i++) {
        if (Math.abs(soundLevels[i] - targetLevels[i]) > gameConfig.harmonyThreshold) {
            return false;
        }
    }
    return true;
}

// --- Initialization Function for Day 2 ---
/**
 * Initializes Day 2 game elements and state.
 * This function is called by main2.js when Day 2 is selected.
 * @param {HTMLCanvasElement} gameCanvasRef The canvas element.
 * @param {CanvasRenderingContext2D} context The 2D rendering context.
 * @param {function} messageBoxFunction Reference to main2.js's showMessageBox.
 * @param {function} displayGameStatusFunction Reference to main2.js's displayGameStatus.
 * @param {function} updateScoreFunction Reference to main2.js's updateGlobalScore.
 * @param {function} restartGameFunction Reference to main2.js's restartCurrentGame.
 * @param {function} getScoreFunction Reference to main2.js's getGlobalScore.
 * @param {HTMLElement} currentTokensDisplayParam - Not used in Day 2, but kept for consistent API.
 * @param {HTMLElement} sackCapacityDisplayParam - Not used in Day 2, but kept for consistent API.
 * @param {HTMLElement} changeSackButtonParam - Not used in Day 2, but kept for consistent API.
 * @param {HTMLElement} startButtonRefParam - Reference to the global start button.
 * @param {HTMLElement} pauseButtonRefParam - Reference to the global pause button.
 * @param {HTMLElement} restartButtonRefParam - Reference to the global restart button.
 * @param {object} playerConfig - Player details including superpower.
 * @param {number} levelInfo - Current game level.
 */
window.Day2Game.initDay2Game = async function(gameCanvasRef, context, messageBoxFunction, displayGameStatusFunction, updateScoreFunction, restartGameFunction, getScoreFunction, currentTokensDisplayParam, sackCapacityDisplayParam, changeSackButtonParam, startButtonRefParam, pauseButtonRefParam, restartButtonRefParam, playerConfig, levelInfo) {
    console.log("Day 2 Harmony Balancing Game Initializing...");
    canvas = gameCanvasRef; // Assign to local variable
    ctx = context;    // Assign to local variable
    showMessageBoxRef = messageBoxFunction;
    displayGameStatusRef = displayGameStatusFunction;
    updateGlobalScoreRef = updateScoreFunction;
    restartCurrentGameRef = restartGameFunction;
    getGlobalScoreRef = getScoreFunction;

    // Hide Day 1 specific UI elements if they exist
    if (currentTokensDisplayParam && currentTokensDisplayParam.parentElement) currentTokensDisplayParam.parentElement.classList.add('hidden');
    if (sackCapacityDisplayParam && sackCapacityDisplayParam.parentElement) sackCapacityDisplayParam.parentElement.classList.add('hidden');
    if (changeSackButtonParam) changeSackButtonParam.classList.add('hidden');

    day2Player.superpower = playerConfig.superpower;
    day2Player.level = levelInfo; // Use levelInfo for current day

    // Apply game config based on player's superpower
    if (day2Player.superpower) {
        gameConfig = {
            IMPACT: {
                harmonyThreshold: 10,
                scorePerSecondBase: 15, // Increased
                timeLimit: 10 // Changed to 10 seconds
            },
            ADAPT: {
                harmonyThreshold: 15,
                scorePerSecondBase: 10, // Increased
                timeLimit: 10 // Changed to 10 seconds
            },
            EXPAND: {
                harmonyThreshold: 8,
                scorePerSecondBase: 20, // Increased
                timeLimit: 10 // Changed to 10 seconds
            }
        }[day2Player.superpower];
        gameDuration = gameConfig.timeLimit; // Set game duration based on config
    }

    // Ensure canvas pixel dimensions match its current CSS size for clear drawing
    if (canvas && canvas.parentElement) {
        canvas.width = canvas.parentElement.clientWidth;
        canvas.height = canvas.parentElement.clientHeight;
    }


    // Initialize Tone.js audio context
    // This is crucial for audio to work after user interaction
    try {
        if (Tone.context.state !== 'running') {
            await Tone.start(); // Tone should be globally available if index.html loads it correctly
        }
        console.log("Tone.js audio context started.");
    } catch (e) {
        console.error("Failed to start Tone.js audio context:", e);
        showMessageBoxRef('Audio Error', 'Could not start game audio. Please ensure your browser allows autoplay or interact with the page first.', null, 3000);
    }

    // Setup melody synth
    if (!melodySynth) { // Only create if it doesn't exist
        melodySynth = new Tone.Synth({
            oscillator: { type: "sine" }, // A clear, bell-like tone
            envelope: {
                attack: 0.01,
                decay: 0.2,
                sustain: 0.1,
                release: 0.5
            }
        }).toDestination();
        melodySynth.volume.value = -25; // Adjusted volume for melody
    }

    // Create the melody sequence
    if (!melodySequence) { // Only create if it doesn't exist
        melodySequence = new Tone.Sequence((time, event) => {
            melodySynth.triggerAttackRelease(event.note, event.duration, time);
        }, JINGLE_BELLS_MELODY_EVENTS);
        melodySequence.loop = true;
        melodySequence.playbackRate = 1.0;
    }

    // Setup click feedback synth
    if (!clickSynth) { // Only create if it doesn't exist
        clickSynth = new Tone.PolySynth(Tone.Synth, {
            oscillator: {
                type: "sine" // A clean, simple tone for clicks
            },
            envelope: {
                attack: 0.01,
                decay: 0.1,
                sustain: 0.01,
                release: 0.1
            }
        }).toDestination();
        clickSynth.volume.value = -10; // Louder for feedback
    }

    // Add a resize listener to handle window resizing dynamically
    // The onWindowResize function is now correctly defined below and uses local 'canvas' and 'ctx'
    window.addEventListener('resize', onWindowResize);


    window.Day2Game.resetDay2Game(); // Call reset via the global object
    console.log("Day 2 Harmony Balancing Game Initialized with superpower:", day2Player.superpower);
};

/**
 * Resets the game state for Day 2 (Harmony Balancing Game).
 */
window.Day2Game.resetDay2Game = function() { // Exposed this directly
    harmonyScore = 0;
    soundLevels = Array(NUM_SOUND_LEVELS).fill(LEVEL_MAX / 2); // Start all levels in the middle
    generateTargetLevels(); // Generate new target levels
    timeRemaining = gameDuration;
    gameRunning = false;
    lastFrameTime = 0;
    clickedBarIndex = -1; // Reset click feedback
    clickFlashEndTime = 0;

    if (animationFrameId) {
        cancelAnimationFrame(animationFrameId);
    }
    removeInputListeners(); // Remove input listeners when game resets

    // Stop synths if they exist
    if (melodySequence) {
        melodySequence.stop();
        Tone.Transport.stop(); // Ensure Tone.js transport is stopped
    }
    if (clickSynth) {
        clickSynth.releaseAll();
    }

    // Ensure canvas and ctx are available before drawing
    if (canvas && ctx) {
        drawGame(performance.now()); // Draw initial state
    } else {
        console.warn("Canvas or context not available during Day2Game.resetDay2Game for initial draw.");
    }
};

/**
 * Starts the Day 2 game loop.
 */
window.Day2Game.startGame = function() {
    if (gameRunning) return;
    gameRunning = true;
    addInputListeners(); // Add input listeners when game starts
    showMessageBoxRef('Day 2: The Harmony Balancing Game', `Your mission is to balance the sound levels to create perfect harmony!
        <br><br>Click the <b>top half</b> of a bar or press keys <b>1, 2, 3</b> to increase its level.
        <br>Click the <b>bottom half</b> of a bar to decrease its level.
        <br><br>Levels will gradually fall, so keep tapping to maintain harmony!
        <br>Your superpower (${day2Player.superpower}) affects how precise you need to be and your score.
        <br><br>Score as high as you can in ${gameDuration} seconds!`, () => {
        lastFrameTime = performance.now(); // Initialize for the game loop
        gameLoop(lastFrameTime);
        // Start the melody sequence
        if (melodySequence) {
            melodySequence.start(0); // Start from the beginning
            Tone.Transport.start(); // Start Tone.js transport
        }
    }, 0, true); // Added true for isHtmlContent
    console.log("Day 2 Harmony Balancing Game Started.");
};

/**
 * Pauses the Day 2 game.
 */
window.Day2Game.pauseGame = function() {
    if (!gameRunning) return;
    gameRunning = false;
    if (animationFrameId) {
        cancelAnimationFrame(animationFrameId);
    }
    removeInputListeners(); // Remove input listeners when game pauses
    // Stop melody sound when paused
    if (melodySequence) {
        melodySequence.stop();
        Tone.Transport.stop();
    }
    if (clickSynth) {
        clickSynth.releaseAll();
    }

    showMessageBoxRef('Game Paused', 'The Harmony Game is on hold. Click OK to resume.', () => {
        lastFrameTime = performance.now(); // Reset lastFrameTime to prevent time jump
        gameLoop(lastFrameTime);
        // Resume melody sound
        if (melodySequence) {
            melodySequence.start(Tone.Transport.now()); // Resume from current time
            Tone.Transport.start();
        }
    }, 0, true); // Added true for isHtmlContent
    console.log("Day 2 Harmony Balancing Game Paused.");
};

/**
 * The main game loop for Day 2 (Harmony Balancing Game).
 * @param {number} currentTime The current time in milliseconds.
 */
function gameLoop(currentTime) {
    if (!gameRunning) return;

    const deltaTime = (currentTime - lastFrameTime) / 1000; // Convert to seconds
    lastFrameTime = currentTime;

    updateGame(deltaTime, currentTime); // Pass currentTime for click feedback
    drawGame(currentTime); // Pass currentTime for click feedback

    animationFrameId = requestAnimationFrame(gameLoop);
}

/**
 * Updates game state for Day 2 (Harmony Balancing Game).
 * @param {number} deltaTime Time elapsed since last frame in seconds.
 * @param {number} currentTime Current time in milliseconds.
 */
function updateGame(deltaTime, currentTime) {
    timeRemaining -= deltaTime;

    if (timeRemaining <= 0) {
        timeRemaining = 0;
        endGame(); // Game ends after time runs out
        return;
    }

    // Levels gradually fall
    for (let i = 0; i < NUM_SOUND_LEVELS; i++) {
        soundLevels[i] = Math.max(LEVEL_MIN, soundLevels[i] - FALL_RATE * deltaTime);
    }

    // Score based on closeness to perfection (harmony factor)
    const currentHarmonyFactor = calculateHarmonyFactor();
    harmonyScore += gameConfig.scorePerSecondBase * currentHarmonyFactor * deltaTime;

    // Update score display in main2.js
    updateGlobalScoreRef(Math.floor(harmonyScore));

    // No dynamic audio adjustments for the melody synth based on harmony factor,
    // as it's now playing a fixed tune. Visuals will handle harmony feedback.
}

/**
 * Draws game elements for Day 2 (Harmony Balancing Game).
 * @param {number} currentTime Current time in milliseconds for click feedback.
 */
function drawGame(currentTime) {
    // Use local canvas and ctx
    if (!ctx || !canvas) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height); // Clear canvas
    ctx.fillStyle = COLORS.eposPetrolDark;
    ctx.fillRect(0, 0, canvas.width, canvas.height);


    // Calculate harmony factor
    const harmonyFactor = calculateHarmonyFactor(); // 0 (worst) to 1 (best)

    let backgroundColor;
    const baseRgb = hexToRgb(COLORS.eposPetrolDark);
    const perfectHarmonyRgb = hexToRgb(COLORS.perfectHarmonyGlow);
    const disharmonyRgb = hexToRgb(COLORS.disharmonyGlow);

    // Blend based on harmonyFactor
    if (harmonyFactor < 0.5) {
        // Blend from base towards red (disharmonyGlow)
        // Factor for blending: 0 at harmonyFactor 0.5, 1 at harmonyFactor 0
        const blendFactor = (0.5 - harmonyFactor) * 2;
        backgroundColor = lerpColor(baseRgb, disharmonyRgb, blendFactor);
    } else {
        // Blend from base towards green (perfectHarmonyGlow)
        // Factor for blending: 0 at harmonyFactor 0.5, 1 at harmonyFactor 1
        const blendFactor = (harmonyFactor - 0.5) * 2;
        backgroundColor = lerpColor(baseRgb, perfectHarmonyRgb, blendFactor);
    }

    ctx.fillStyle = backgroundColor;
    ctx.fillRect(0, 0, canvas.width, canvas.height);


    const barWidth = 60;
    const barSpacing = 40;
    const startX = (canvas.width - (NUM_SOUND_LEVELS * barWidth + (NUM_SOUND_LEVELS - 1) * barSpacing)) / 2;
    const barHeightScale = (canvas.height - 200) / LEVEL_MAX; // Scale bars to fit canvas, leave space for text

    // Draw sound level bars
    for (let i = 0; i < NUM_SOUND_LEVELS; i++) {
        const x = startX + i * (barWidth + barSpacing);
        const y = canvas.height - 100; // Base of the bar

        // Draw bar background
        ctx.fillStyle = COLORS.eposPetrolLight; // Lighter petrol for empty part
        ctx.fillRect(x, y - LEVEL_MAX * barHeightScale, barWidth, LEVEL_MAX * barHeightScale);

        // Determine fill color (with click highlight)
        let fillColor = COLORS.eposMint; // Default EPOS Mint
        if (i === clickedBarIndex && currentTime < clickFlashEndTime) {
            fillColor = COLORS.clickHighlight; // Flash white on click
        }
        ctx.fillStyle = fillColor;

        // Draw current level
        const currentBarHeight = soundLevels[i] * barHeightScale;
        ctx.fillRect(x, y - currentBarHeight, barWidth, currentBarHeight);

        // Draw target level indicator
        ctx.fillStyle = COLORS.goldAccent; // Gold accent for target line
        const targetBarHeight = targetLevels[i] * barHeightScale;
        ctx.fillRect(x, y - targetBarHeight - 2, barWidth, 4); // Thin line for target

        // Draw bar number
        ctx.fillStyle = 'white';
        ctx.font = 'bold 20px Inter';
        ctx.textAlign = 'center';
        ctx.fillText(`Level ${i + 1}`, x + barWidth / 2, y + 30);
    }

    // Add a glow effect if currently in perfect harmony (within threshold)
    if (isInHarmony()) {
        ctx.shadowColor = COLORS.perfectHarmonyGlow;
        ctx.shadowBlur = 20;
        ctx.shadowOffsetX = 0;
        ctx.shadowOffsetY = 0;
    }

    // Display game info
    ctx.fillStyle = 'white';
    ctx.font = '24px Inter';
    ctx.textAlign = 'left';
    ctx.fillText(`Time: ${Math.floor(timeRemaining)}s`, 20, 40);
    ctx.fillText(`Harmony Score: ${Math.floor(harmonyScore)}`, 20, 70);

    // Reset shadow after drawing score
    ctx.shadowBlur = 0;
    ctx.shadowColor = 'transparent';


    // Display target levels (for debugging/player guidance) - can be removed later
    ctx.fillStyle = 'rgba(255, 255, 255, 0.7)'; // Slightly transparent
    ctx.font = '16px Inter';
    ctx.fillText(`Targets: ${targetLevels.join(', ')} (Threshold: ${gameConfig.harmonyThreshold})`, 20, canvas.height - 20);
}

/**
 * Handles canvas clicks for Day 2 (Harmony Balancing Game).
 * @param {MouseEvent} event The click event.
 */
function handleCanvasClick(event) {
    if (!gameRunning) return;

    const rect = canvas.getBoundingClientRect(); // Use local canvas
    const mouseX = event.clientX - rect.left;
    const mouseY = event.clientY - rect.top;

    const barWidth = 60;
    const barSpacing = 40;
    const startX = (canvas.width - (NUM_SOUND_LEVELS * barWidth + (NUM_SOUND_LEVELS - 1) * barSpacing)) / 2;
    const barHeightScale = (canvas.height - 200) / LEVEL_MAX;
    const baseY = canvas.height - 100;

    for (let i = 0; i < NUM_SOUND_LEVELS; i++) {
        const x = startX + i * (barWidth + barSpacing);
        const barTopY = baseY - LEVEL_MAX * barHeightScale;
        const barBottomY = baseY;

        // Check if click is within this bar's horizontal bounds
        if (mouseX >= x && mouseX <= x + barWidth) {
            // Check if click is within this bar's vertical bounds
            if (mouseY >= barTopY && mouseY <= barBottomY) {
                // Determine if top half (increase) or bottom half (decrease)
                const clickHeight = mouseY - barTopY;
                const barMidHeight = (barBottomY - barTopY) / 2;

                if (clickHeight < barMidHeight) {
                    // Clicked top half: increase level
                    soundLevels[i] = Math.min(LEVEL_MAX, soundLevels[i] + LEVEL_ADJUST_CLICK_AMOUNT);
                } else {
                    // Clicked bottom half: decrease level
                    soundLevels[i] = Math.max(LEVEL_MIN, soundLevels[i] - LEVEL_ADJUST_CLICK_AMOUNT);
                }
                // Play sound feedback for bar adjustment
                if (clickSynth) {
                    clickSynth.triggerAttackRelease(BAR_CLICK_NOTES[i], '16n');
                }
                // Set click feedback
                clickedBarIndex = i;
                clickFlashEndTime = performance.now() + CLICK_FLASH_DURATION;
                break; // Process only one bar per click
            }
        }
    }
}

/**
 * Handles keyboard input for Day 2 (Harmony Balancing Game).
 * @param {KeyboardEvent} event The keyboard event.
 */
function handleKeyDown(event) {
    if (!gameRunning) return;

    let barToAdjust = -1;
    if (event.key === '1') {
        barToAdjust = 0;
    } else if (event.key === '2') {
        barToAdjust = 1;
    } else if (event.key === '3') {
        barToAdjust = 2;
    }

    if (barToAdjust !== -1) {
        soundLevels[barToAdjust] = Math.min(LEVEL_MAX, soundLevels[barToAdjust] + LEVEL_ADJUST_CLICK_AMOUNT);
        // Play sound feedback for bar adjustment
        if (clickSynth) {
            clickSynth.triggerAttackRelease(BAR_CLICK_NOTES[barToAdjust], '16n');
        }
        // Trigger visual feedback for keyboard press
        clickedBarIndex = barToAdjust;
        clickFlashEndTime = performance.now() + CLICK_FLASH_DURATION;
    }
}

/**
 * Ends the Day 2 Harmony Balancing Game.
 */
function endGame() {
    gameRunning = false;
    if (animationFrameId) {
        cancelAnimationFrame(animationFrameId);
    }
    removeInputListeners(); // Remove input listeners when game ends

    // Stop melody sound when game ends
    if (melodySequence) {
        melodySequence.stop();
        Tone.Transport.stop();
    }
    if (clickSynth) {
        clickSynth.releaseAll();
    }

    showMessageBoxRef('Time\'s Up!', `Your final Harmony Score: ${Math.floor(harmonyScore)}. Can you beat your high score?
    <br><br>To enter today's prize draw the secret code is <b>IMPACT 1060T ANC</b>`, () => {
        window.advanceToNextLevel(); // Call global function to advance to next day
    }, 0, true); // Added true for isHtmlContent
}

// --- Input Handling ---
function addInputListeners() {
    // Use local canvas for event listeners
    if (canvas) { // Ensure canvas is initialized
        canvas.addEventListener('click', handleCanvasClick);
    }
    document.addEventListener('keydown', handleKeyDown); // Add keyboard listener
    console.log("Day 2 input listeners added.");
}

function removeInputListeners() {
    // Use local canvas for event listeners
    if (canvas) { // Ensure canvas is initialized
        canvas.removeEventListener('click', handleCanvasClick);
    }
    document.removeEventListener('keydown', handleKeyDown); // Remove keyboard listener
    console.log("Day 2 input listeners removed.");
}

// --- Window Resize Handler ---
// Expose this function globally via window.Day2Game
window.Day2Game.onWindowResize = function() {
    // Use local canvas and ctx
    if (canvas && canvas.parentElement) {
        canvas.width = canvas.parentElement.clientWidth;
        canvas.height = canvas.parentElement.clientHeight;
        // Re-calculate bar positions and sizes based on new canvas dimensions
        // (These calculations are already done dynamically in drawGame, so no explicit update needed here)
        drawGame(performance.now()); // Redraw the game after resize
    }
};

console.log("Day2.js script loaded.");

})(); // End of IIFE
