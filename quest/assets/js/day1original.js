/**
 * day1.js
 *
 * Contains the specific game logic for Day 1: Santa's Sleigh Service.
 * This module is designed to be imported and controlled by main2.js.
 *
 * @version 1.0.9
 * @date 2025-07-14
 * @author Gemini Assistant
 */

// --- Internal Game State for Day 1 ---
let canvas;
let ctx;
let showMessageBoxRef; // Reference to the showMessageBox function from main2.js
let updateGlobalScoreRef; // Reference to the updateGlobalScore function from main2.js
let restartCurrentGameRef; // Reference to function in main2.js to restart current day's game
let currentGlobalScoreRef; // Reference to the current global score from main2.js

let sleigh = {
    x: 100, // Sleigh's X position on canvas
    y: 225, // Centered vertically initially
    width: 80,
    height: 50,
    speedY: 0, // Vertical movement speed
    moveSpeedX: 4, // How fast sleigh moves left/right on its own plane
    xBoundsLeft: 50, // Sleigh can't go further left than this
    xBoundsRight: 300, // Sleigh can't go further right than this (e.g., about 1/3 of canvas width)

    scrollSpeed: 3, // Current horizontal scroll speed of game elements (environment speed)
    targetScrollSpeed: 3, // Target horizontal scroll speed (for smooth transitions)
    minScrollSpeed: 2, // Minimum horizontal scroll speed
    maxScrollSpeed: 6, // Maximum horizontal speed
    scrollSpeedChangeRate: 0.1, // How quickly horizontal scroll speed changes

    maxSpeedY: 5, // Max vertical speed
    accelerationY: 0.5, // How quickly it accelerates up/down
    decelerationY: 0.2, // How quickly it slows down when no key pressed
    currentTokens: 0, // Tokens currently on the sleigh
    sackCapacity: 50 // Default sack capacity, adjusted by superpower
};

let obstacles = []; // Initialized here and reset in initDay1Game
let tokens = [];    // Initialized here and reset in initDay1Game

// Game configuration based on superpower (will be passed from main2.js)
let gameConfig = {};

// Object generation timers/intervals
let obstacleSpawnInterval = 1500; // milliseconds
let tokenSpawnInterval = 800; // milliseconds
let lastObstacleSpawnTime = 0;
let lastTokenSpawnTime = 0;

let animationFrameId; // For the internal game loop
let gameRunning = false; // Internal state for Day 1 game

// UI elements specific to Day 1 game (references passed from main2.js)
let currentTokensDisplay;
let sackCapacityDisplay;
let changeSackButton;
let startButtonRef;
let pauseButtonRef;
let restartButtonRef;

// Background scrolling variables
let backgroundX = 0;
const backgroundSpeedMultiplier = 0.5; // Background scrolls slower than foreground elements

// --- Difficulty and Timer Variables ---
let gameTime = 0; // Time in milliseconds since game started
let difficultyLevel = 1;
const difficultyIncreaseInterval = 5000; // Increase difficulty every 5 seconds
let lastDifficultyIncreaseTime = 0; // Timestamp when difficulty was last increased

// --- Game Assets (Images) ---
const ASSET_PATHS = {
    sleigh: './assets/images/day1/sleigh.png', // Placeholder, replace with your actual path
    token10: './assets/images/day1/token10.png', // Placeholder
    token20: './assets/images/day1/token20.png', // Placeholder
    token30: './assets/images/day1/token30.png', // Placeholder
    noiseObstacle: './assets/images/day1/noiseObstacle.png', // Placeholder
    comfortObstacle: './assets/images/day1/comfortObstacle.png', // Placeholder
};
let assets = {}; // To store loaded Image objects

/**
 * Loads all game assets (images).
 * @returns {Promise<void>} A promise that resolves when all assets are loaded.
 */
function loadAssets() {
    const assetPromises = [];
    // Clear previous loading message before drawing new one
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#0a0a0a';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.font = '24px Inter';
    ctx.fillStyle = '#ffffff';
    ctx.textAlign = 'center';
    ctx.fillText('Loading Sleigh Service Assets...', canvas.width / 2, canvas.height / 2);


    for (const key in ASSET_PATHS) {
        const img = new Image();
        img.src = ASSET_PATHS[key];
        const promise = new Promise((resolve, reject) => {
            img.onload = () => {
                assets[key] = img;
                resolve();
            };
            img.onerror = () => {
                console.error(`Failed to load image: ${ASSET_PATHS[key]}`);
                // Reject the promise so Promise.all will catch it
                reject(new Error(`Failed to load image: ${ASSET_PATHS[key]}`));
            };
        });
        assetPromises.push(promise);
    }
    return Promise.all(assetPromises);
}


// --- Input Handling (local to Day 1 game) ---
let keys = {
    ArrowUp: false,
    ArrowDown: false,
    ArrowLeft: false, // For horizontal movement AND speed control
    ArrowRight: false, // For horizontal movement AND speed control
    KeyC: false // Changed from Space to 'c' key
};

function addInputListeners() {
    document.addEventListener('keydown', handleKeyDown);
    document.addEventListener('keyup', handleKeyUp);
    canvas.addEventListener('touchstart', handleTouchStart);
    canvas.addEventListener('touchmove', handleTouchMove);
    canvas.addEventListener('touchend', handleTouchEnd);
}

function removeInputListeners() {
    document.removeEventListener('keydown', handleKeyDown);
    document.removeEventListener('keyup', handleKeyUp);
    canvas.removeEventListener('touchstart', handleTouchStart);
    canvas.removeEventListener('touchmove', handleTouchMove);
    canvas.removeEventListener('touchend', handleTouchEnd);
}

function handleKeyDown(e) {
    // Prevent default browser scrolling for specific keys immediately
    if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'KeyC'].includes(e.code)) {
        e.preventDefault();
    }

    // Update state for continuous movement keys
    if (e.code === 'ArrowUp') keys.ArrowUp = true;
    else if (e.code === 'ArrowDown') keys.ArrowDown = true;
    else if (e.code === 'ArrowLeft') keys.ArrowLeft = true;
    else if (e.code === 'ArrowRight') keys.ArrowRight = true;

    // Handle 'c' key for single action (change sack)
    // Only trigger if game is running and key was not already down
    if (e.code === 'KeyC' && gameRunning && !keys.KeyC) {
        changeSack();
        keys.KeyC = true; // Mark as pressed to prevent repeat on hold
    }
}

function handleKeyUp(e) {
    // Reset state for all keys
    if (e.code === 'ArrowUp') keys.ArrowUp = false;
    else if (e.code === 'ArrowDown') keys.ArrowDown = false;
    else if (e.code === 'ArrowLeft') keys.ArrowLeft = false;
    else if (e.code === 'ArrowRight') keys.ArrowRight = false;
    else if (e.code === 'KeyC') keys.KeyC = false; // Allow next press
}

let touchStartY = 0;
let touchEndY = 0;
const touchThreshold = 20; // Minimum swipe distance

function handleTouchStart(e) {
    e.preventDefault(); // Prevent scrolling
    touchStartY = e.touches[0].clientY;
}

function handleTouchMove(e) {
    e.preventDefault(); // Prevent scrolling
    touchEndY = e.touches[0].clientY;
    const deltaY = touchStartY - touchEndY;

    // Determine vertical movement from swipe
    if (deltaY > touchThreshold) { // Swipe Up
        sleigh.speedY = -sleigh.maxSpeedY;
    } else if (deltaY < -touchThreshold) { // Swipe Down
        sleigh.speedY = sleigh.maxSpeedY;
    } else {
        sleigh.speedY = 0; // No significant swipe
    }

    // Horizontal movement from swipe (simplified for now, could be more complex)
    // For now, let's keep touch for vertical only, as horizontal swipe also changes speed
    // This could be a future enhancement if desired.
}

function handleTouchEnd(e) {
    e.preventDefault();
    sleigh.speedY = 0; // Stop vertical movement on touch release
    touchStartY = 0;
    touchEndY = 0;
}


// --- Game Element Classes ---

/**
 * Represents an obstacle.
 * @param {number} x - X position.
 * @param {number} y - Y position.
 * @param {number} width - Width of the obstacle.
 * @param {number} height - Height of the obstacle.
 * @param {string} type - Type of obstacle (e.g., 'noise', 'comfort').
 */
function Obstacle(x, y, width, height, type) {
    this.x = x;
    this.initialY = y; // Store initial Y for oscillation
    this.width = width;
    this.height = height;
    this.type = type;
    this.image = type === 'noise' ? assets.noiseObstacle : assets.comfortObstacle;

    // For subtle vertical movement - ADJUSTED AMPLITUDE FOR SMOOTHER WIGGLE
    this.amplitude = 5 + Math.random() * 5; // How much it wiggles up/down (range 5-10)
    this.frequency = 0.02 + Math.random() * 0.03; // How fast it wiggles (range 0.02-0.05)
    this.phaseOffset = Math.random() * Math.PI * 2; // Starting point in the sine wave

    this.draw = function() {
        if (this.image && this.image.complete) {
            ctx.drawImage(this.image, this.x, this.y, this.width, this.height);
        } else {
            // Fallback to simple drawing if image not loaded
            ctx.fillStyle = this.type === 'noise' ? '#ff5549' : '#00a399';
            ctx.fillRect(this.x, this.y, this.width, this.height);
            ctx.fillStyle = '#ffffff';
            ctx.font = '12px Inter';
            ctx.textAlign = 'center';
            ctx.fillText(this.type.toUpperCase(), this.x + this.width / 2, this.y + this.height / 2 + 4);
        }
    };
}

/**
 * Represents a BrainAdapt token.
 * @param {number} x - X position.
 * @param {number} y - Y position.
 * @param {number} value - Point value of the token (10, 20, or 30).
 */
function Token(x, y, value) {
    this.x = x;
    this.initialY = y; // Store initial Y for oscillation
    this.radius = 15;
    this.value = value;
    this.image = assets[`token${value}`]; // e.g., assets.token10

    // For subtle vertical movement - ADJUSTED AMPLITUDE FOR SMOOTHER WIGGLE
    this.amplitude = 3 + Math.random() * 3; // How much it wiggles up/down (range 3-6)
    this.frequency = 0.03 + Math.random() * 0.04; // How fast it wiggles (range 0.03-0.07)
    this.phaseOffset = Math.random() * Math.PI * 2; // Starting point in the sine wave

    this.draw = function() {
        if (this.image && this.image.complete) {
            ctx.drawImage(this.image, this.x - this.radius, this.y - this.radius, this.radius * 2, this.radius * 2);
        } else {
            // Fallback to simple drawing if image not loaded
            let color = '#fff';
            if (value === 10) color = '#00a399';
            else if (value === 20) color = '#ff5549';
            else if (value === 30) color = '#ffe000';

            ctx.beginPath();
            ctx.arc(this.x, this.y, this.radius, 0, Math.PI * 2);
            ctx.fillStyle = color;
            ctx.fill();
            ctx.strokeStyle = '#131313';
            ctx.lineWidth = 2;
            ctx.stroke();

            ctx.fillStyle = '#131313';
            ctx.font = 'bold 12px Inter';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(this.value, this.x, this.y);
        }
    };
}

// --- Game Logic Functions ---

/**
 * Updates the current tokens and sack capacity display.
 */
function updateTokenDisplays() {
    if (currentTokensDisplay) {
        currentTokensDisplay.textContent = `${sleigh.currentTokens}`;
    }
    if (sackCapacityDisplay) {
        sackCapacityDisplay.textContent = `${sleigh.sackCapacity}`;
    }
}

/**
 * Handles changing the sack.
 * Adds current tokens to score and resets current tokens.
 */
function changeSack() {
    if (sleigh.currentTokens > 0) {
        updateGlobalScoreRef(sleigh.currentTokens); // Update global score in main2.js
        sleigh.currentTokens = 0;
        updateTokenDisplays();
        showMessageBoxRef('Sack Emptied!', 'Tokens added to your score!', 1500); // Shorter duration
    } else {
        showMessageBoxRef('Sack Empty', 'No tokens to add to your score yet!', 1500); // Shorter duration
    }
}

/**
 * Updates the game state for Day 1.
 */
function updateDay1Game() {
    const currentTime = Date.now();

    // --- Game Timer and Difficulty Progression ---
    if (gameRunning) { // Only update timer if game is active
        gameTime = currentTime - lastDifficultyIncreaseTime; // Time since last difficulty increase

        if (gameTime >= difficultyIncreaseInterval) {
            difficultyLevel++;
            lastDifficultyIncreaseTime = currentTime; // Reset timer for next increase

            // Increase difficulty:
            // 1. Increase base scroll speed
            sleigh.targetScrollSpeed = Math.min(sleigh.maxScrollSpeed, sleigh.targetScrollSpeed + 0.2); // Increase by 0.2
            // 2. Make obstacles/tokens spawn more frequently (decrease intervals)
            obstacleSpawnInterval = Math.max(400, obstacleSpawnInterval - 50); // Min 400ms
            tokenSpawnInterval = Math.max(200, tokenSpawnInterval - 30); // Min 200ms

            showMessageBoxRef(`Level Up!`, `Difficulty Level: ${difficultyLevel}`, 1500);
        }
    }


    // --- Sleigh Vertical Movement ---
    if (keys.ArrowUp) {
        sleigh.speedY = Math.max(-sleigh.maxSpeedY, sleigh.speedY - sleigh.accelerationY);
    } else if (keys.ArrowDown) {
        sleigh.speedY = Math.min(sleigh.maxSpeedY, sleigh.speedY + sleigh.accelerationY);
    } else {
        // Decelerate when no key is pressed
        if (sleigh.speedY > 0) {
            sleigh.speedY = Math.max(0, sleigh.speedY - sleigh.decelerationY);
        } else if (sleigh.speedY < 0) {
            sleigh.speedY = Math.min(0, sleigh.speedY + sleigh.decelerationY);
        }
    }
    sleigh.y += sleigh.speedY;

    // Keep sleigh within canvas vertical bounds
    sleigh.y = Math.max(0, Math.min(canvas.height - sleigh.height, sleigh.y));

    // --- Sleigh Horizontal Movement AND Scroll Speed Control ---
    if (keys.ArrowRight) {
        // Move sleigh right
        sleigh.x = Math.min(sleigh.xBoundsRight - sleigh.width, sleigh.x + sleigh.moveSpeedX);
        // Increase target scroll speed
        sleigh.targetScrollSpeed = Math.min(sleigh.maxScrollSpeed, sleigh.targetScrollSpeed + sleigh.scrollSpeedChangeRate);
    } else if (keys.ArrowLeft) {
        // Move sleigh left
        sleigh.x = Math.max(sleigh.xBoundsLeft, sleigh.x - sleigh.moveSpeedX);
        // Decrease target scroll speed
        sleigh.targetScrollSpeed = Math.max(sleigh.minScrollSpeed, sleigh.targetScrollSpeed - sleigh.scrollSpeedChangeRate);
    } else {
        // Gradually return to base scroll speed if no horizontal key pressed
        if (sleigh.scrollSpeed > gameConfig.sleighScrollSpeed) {
            sleigh.targetScrollSpeed = Math.max(gameConfig.sleighScrollSpeed, sleigh.targetScrollSpeed - sleigh.scrollSpeedChangeRate * 0.5);
        } else if (sleigh.scrollSpeed < gameConfig.sleighScrollSpeed) {
            sleigh.targetScrollSpeed = Math.min(gameConfig.sleighScrollSpeed, sleigh.targetScrollSpeed + sleigh.scrollSpeedChangeRate * 0.5);
        }
    }
    // Smoothly interpolate current scroll speed towards target scroll speed
    sleigh.scrollSpeed += (sleigh.targetScrollSpeed - sleigh.scrollSpeed) * 0.1;


    // --- Move obstacles and tokens ---
    obstacles.forEach(obs => {
        obs.x -= sleigh.scrollSpeed;
        obs.y = obs.initialY + obs.amplitude * Math.sin(currentTime * obs.frequency + obs.phaseOffset);
    });
    tokens.forEach(tok => {
        tok.x -= sleigh.scrollSpeed;
        tok.y = tok.initialY + tok.amplitude * Math.sin(currentTime * tok.frequency + tok.phaseOffset);
    });

    // Update scrolling background
    backgroundX -= sleigh.scrollSpeed * backgroundSpeedMultiplier;
    if (backgroundX <= -canvas.width) { // Reset when one full canvas width has scrolled
        backgroundX += canvas.width;
    }

    // Remove off-screen elements
    obstacles = obstacles.filter(obs => obs.x + obs.width > 0);
    tokens = tokens.filter(tok => tok.x + tok.radius > 0);

    // Spawn new obstacles
    if (currentTime - lastObstacleSpawnTime > obstacleSpawnInterval) {
        const obsWidth = 50 + Math.random() * 50;
        const obsHeight = 50 + Math.random() * 50;
        const obsY = Math.random() * (canvas.height - obsHeight);
        const obsType = Math.random() < 0.5 ? 'noise' : 'comfort';
        obstacles.push(new Obstacle(canvas.width, obsY, obsWidth, obsHeight, obsType));
        lastObstacleSpawnTime = currentTime;
        // obstacleSpawnInterval is now managed by difficulty progression
    }

    // Spawn new tokens
    if (currentTime - lastTokenSpawnTime > tokenSpawnInterval) {
        const tokenValue = gameConfig.tokenValues[Math.floor(Math.random() * gameConfig.tokenValues.length)];
        const tokenY = Math.random() * (canvas.height - 30) + 15; // Ensure token is fully visible
        tokens.push(new Token(canvas.width, tokenY, tokenValue));
        lastTokenSpawnTime = currentTime;
        // tokenSpawnInterval is now managed by difficulty progression
    }

    // Collision detection: Sleigh vs. Obstacles
    for (let i = obstacles.length - 1; i >= 0; i--) {
        const obs = obstacles[i];
        // More accurate AABB collision for rects
        if (sleigh.x < obs.x + obs.width &&
            sleigh.x + sleigh.width > obs.x &&
            sleigh.y < obs.y + obs.height &&
            sleigh.y + sleigh.height > obs.y) {
            // Collision!
            pauseDay1Game(); // Pause this game
            showMessageBoxRef('CRASH!', `Oh no! You hit a ${obs.type} obstacle. Your quest for Day 1 ends here. Final Score: ${currentGlobalScoreRef()}. Level Reached: ${difficultyLevel}`, () => { // Added level reached
                restartCurrentGameRef(); // Trigger restart of current game in main2.js
            }, 4000); // Longer duration for game over message
            return; // Stop updating if crashed
        }
    }


    // Collision detection: Sleigh vs. Tokens
    for (let i = tokens.length - 1; i >= 0; i--) {
        const tok = tokens[i];
        // Simple circle-rect collision approx
        const dx = (sleigh.x + sleigh.width / 2) - tok.x;
        const dy = (sleigh.y + sleigh.height / 2) - tok.y;
        const distance = Math.sqrt(dx * dx + dy * dy);

        if (distance < (sleigh.width / 2 + tok.radius)) {
            if (sleigh.currentTokens + tok.value <= sleigh.sackCapacity) {
                sleigh.currentTokens += tok.value;
                updateTokenDisplays();
                tokens.splice(i, 1); // Remove collected token
            } else {
                // Token falls off
                tokens.splice(i, 1);
                showMessageBoxRef('Sack Full!', 'A token fell off because your sack is full! Press C to change sack.', 2000); // Updated message
            }
        }
    }
}

/**
 * Draws the scrolling background.
 */
function drawBackground() {
    // Draw repeating pattern of stars/dots
    ctx.fillStyle = '#002025'; // Darker petrol for background
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    // Draw repeating pattern (simple stars or dots)
    ctx.fillStyle = '#00454d'; // Slightly lighter petrol for stars
    const starSize = 2; // Max star radius
    const numStars = 100; // Number of stars
    // Draw two sets of stars to ensure seamless scrolling
    for (let i = 0; i < numStars; i++) {
        // Star 1
        let x1 = (i * (canvas.width / numStars) * 2 + backgroundX) % (canvas.width * 2);
        let y1 = (i * (canvas.height / numStars) * 1.5 + backgroundX * 0.1) % (canvas.height * 1.5); // Slight vertical parallax
        ctx.beginPath();
        ctx.arc(x1, y1, Math.random() * starSize, 0, Math.PI * 2);
        ctx.fill();

        // Star 2 (offset for seamless wrap)
        let x2 = (x1 + canvas.width) % (canvas.width * 2);
        let y2 = (y1 + canvas.height / 2) % (canvas.height * 1.5); // Offset vertically too for variety
        ctx.beginPath();
        ctx.arc(x2, y2, Math.random() * starSize, 0, Math.PI * 2);
        ctx.fill();
    }
}

/**
 * Draws the sleigh with its sprite.
 */
function drawSleigh() {
    if (assets.sleigh && assets.sleigh.complete) {
        ctx.drawImage(assets.sleigh, sleigh.x, sleigh.y, sleigh.width, sleigh.height);
    } else {
        // Fallback drawing if image not loaded
        ctx.fillStyle = '#ffffff'; // White sleigh
        ctx.fillRect(sleigh.x, sleigh.y, sleigh.width, sleigh.height);
        ctx.strokeStyle = '#ff5549'; // Coral border
        ctx.lineWidth = 3;
        ctx.strokeRect(sleigh.x, sleigh.y, sleigh.width, sleigh.height);
        ctx.beginPath();
        ctx.moveTo(sleigh.x + sleigh.width, sleigh.y + sleigh.height / 2);
        ctx.lineTo(sleigh.x + sleigh.width + 15, sleigh.y + sleigh.height / 2 - 15);
        ctx.lineTo(sleigh.x + sleigh.width + 15, sleigh.y + sleigh.height / 2 + 15);
        ctx.closePath();
        ctx.fillStyle = '#00a399'; // Teal nose
        ctx.fill();
    }
}

/**
 * Draws the sack fullness indicator above the sleigh.
 */
function drawSackIndicator() {
    const barWidth = sleigh.width * 0.8;
    const barHeight = 8;
    const barX = sleigh.x + (sleigh.width - barWidth) / 2;
    const barY = sleigh.y - barHeight - 10; // 10 pixels above sleigh

    // Draw background of the bar
    ctx.fillStyle = '#333333'; // Dark grey background
    ctx.fillRect(barX, barY, barWidth, barHeight);
    ctx.strokeStyle = '#ffffff'; // White border
    ctx.lineWidth = 1;
    ctx.strokeRect(barX, barY, barWidth, barHeight);

    // Calculate fill percentage
    const fillPercentage = sleigh.currentTokens / sleigh.sackCapacity;
    const filledWidth = barWidth * fillPercentage;

    // Determine fill color based on fullness
    let fillColor;
    if (fillPercentage < 0.5) {
        fillColor = '#00a399'; // Greenish (EPOS Teal)
    } else if (fillPercentage < 0.8) {
        fillColor = '#ffe000'; // Yellowish
    } else {
        fillColor = '#ff5549'; // Reddish (EPOS Coral)
    }

    // Draw the filled portion
    ctx.fillStyle = fillColor;
    ctx.fillRect(barX, barY, filledWidth, barHeight);
}

/**
 * Draws the current difficulty level on the canvas.
 */
function drawDifficultyLevel() {
    ctx.fillStyle = '#ffffff'; // White text
    ctx.font = 'bold 20px Inter';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'top';
    ctx.fillText(`Level: ${difficultyLevel}`, canvas.width - 20, 20); // Top right corner
}

/**
 * Draws all game elements for Day 1.
 */
function drawDay1Game() {
    drawBackground(); // Draw scrolling background first
    drawSleigh();     // Draw the enhanced sleigh
    drawSackIndicator(); // Draw the sack fullness indicator
    obstacles.forEach(obs => obs.draw()); // Draw enhanced obstacles
    tokens.forEach(tok => tok.draw()); // Draw enhanced tokens
    drawDifficultyLevel(); // Draw the current difficulty level
}

/**
 * Main game loop for Day 1.
 */
function gameLoop() {
    if (!gameRunning) return;

    // No need to clearRect here as drawBackground fills the whole canvas
    updateDay1Game();
    drawDay1Game();

    animationFrameId = requestAnimationFrame(gameLoop);
}

// --- Exported Functions for main2.js ---

/**
 * Initializes the Day 1 game.
 * @param {HTMLCanvasElement} canvasElement - The canvas DOM element.
 * @param {CanvasRenderingContext2D} context - The 2D rendering context.
 * @param {object} playerConfig - Player details including superpower.
 * @param {function} messageBoxFunction - Reference to main2.js's showMessageBox.
 * @param {object} uiElements - References to Day 1 specific UI elements.
 * @param {function} updateScoreFunction - Function to update the global score in main2.js.
 * @param {function} restartGameFunction - Function to restart the current game in main2.js.
 * @param {function} getGlobalScoreFunction - Function to get the current global score from main2.js.
 */
export async function initDay1Game(canvasElement, context, playerConfig, messageBoxFunction, uiElements, updateScoreFunction, restartGameFunction, getGlobalScoreFunction) {
    canvas = canvasElement;
    ctx = context;
    showMessageBoxRef = messageBoxFunction;
    updateGlobalScoreRef = updateScoreFunction; // Store reference to update global score
    restartCurrentGameRef = restartGameFunction; // Store reference to restart current game
    currentGlobalScoreRef = getGlobalScoreFunction; // Store reference to get current global score

    // Initialize obstacles and tokens arrays *before* asset loading,
    // so they are always defined, even if loading fails.
    obstacles = [];
    tokens = [];

    // Show loading message while assets load
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#0a0a0a';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.font = '24px Inter';
    ctx.fillStyle = '#ffffff';
    ctx.textAlign = 'center';
    ctx.fillText('Loading Sleigh Service Assets...', canvas.width / 2, canvas.height / 2);

    try {
        await loadAssets(); // Wait for all images to load
        showMessageBoxRef('Assets Loaded!', 'Ready for launch!', 1000);
    } catch (error) {
        showMessageBoxRef('Error Loading Assets', 'Could not load game images. Please refresh. Check console for details.', 5000);
        console.error("Asset loading error:", error);
        return; // Prevent game from starting if assets fail
    }


    // Apply player superpower configuration
    gameConfig = {
        IMPACT: {
            sleighScrollSpeed: 4.5, // Base scroll speed
            sackCapacity: 50,
            tokenValues: [10, 20, 30]
        },
        ADAPT: {
            sleighScrollSpeed: 3.5,
            sackCapacity: 30,
            tokenValues: [10, 20, 30]
        },
        EXPAND: {
            sleighScrollSpeed: 3.0,
            sackCapacity: 70,
            tokenValues: [10, 20, 30]
        }
    }[playerConfig.superpower];

    sleigh.scrollSpeed = gameConfig.sleighScrollSpeed; // Set initial scroll speed
    sleigh.targetScrollSpeed = gameConfig.sleighScrollSpeed; // Set target scroll speed
    sleigh.minScrollSpeed = gameConfig.sleighScrollSpeed * 0.7; // Define min/max relative to base
    sleigh.maxScrollSpeed = gameConfig.sleighScrollSpeed * 1.3;
    sleigh.sackCapacity = gameConfig.sackCapacity;
    sleigh.currentTokens = 0; // Reset tokens for new game

    // Reset sleigh position and speed for a clean restart
    sleigh.x = 100; // Initial X position
    sleigh.y = canvas.height / 2 - sleigh.height / 2; // Initial Y position (centered)
    sleigh.speedY = 0; // Crucial: Reset vertical speed on init

    // Also reset key states to ensure no lingering 'pressed' state
    keys.ArrowUp = false;
    keys.ArrowDown = false;
    keys.ArrowLeft = false;
    keys.ArrowRight = false;
    keys.KeyC = false;

    // Reset difficulty and timer on init
    gameTime = 0;
    difficultyLevel = 1;
    lastDifficultyIncreaseTime = performance.now(); // Use performance.now() for accurate time


    // Reset spawn intervals to their initial values for a fresh start
    obstacleSpawnInterval = 1500;
    tokenSpawnInterval = 800;
    lastObstacleSpawnTime = 0; // Will be set to current time on first spawn
    lastTokenSpawnTime = 0;   // Will be set to current time on first spawn


    // Reset background position
    backgroundX = 0;

    // Store UI element references
    currentTokensDisplay = uiElements.currentTokensDisplay;
    sackCapacityDisplay = uiElements.sackCapacityDisplay;
    changeSackButton = uiElements.changeSackButton;
    startButtonRef = uiElements.startButton;
    pauseButtonRef = uiElements.pauseButton;
    restartButtonRef = uiElements.restartButton;

    // The changeSackButton is now primarily a visual cue, the action is on 'c' key
    if (changeSackButton) {
        changeSackButton.classList.remove('hidden'); // Ensure it's visible for Day 1
        changeSackButton.textContent = 'Change Sack (Press C)'; // Update button text
    }

    updateTokenDisplays(); // Update new displays
}

/**
 * Starts the Day 1 game loop.
 */
export function startDay1Game() {
    if (!gameRunning) {
        gameRunning = true;
        addInputListeners(); // Add input listeners when game starts
        animationFrameId = requestAnimationFrame(gameLoop);
        lastDifficultyIncreaseTime = performance.now(); // Start timer when game actually begins!

        // Update global control button visibility
        if (startButtonRef) startButtonRef.classList.add('hidden');
        if (pauseButtonRef) pauseButtonRef.classList.remove('hidden');
        if (restartButtonRef) restartButtonRef.classList.remove('hidden');
    }
}

/**
 * Pauses the Day 1 game.
 */
export function pauseDay1Game() {
    if (gameRunning) {
        gameRunning = false;
        cancelAnimationFrame(animationFrameId);
        removeInputListeners(); // Remove input listeners when game pauses

        // Update global control button visibility
        if (startButtonRef) startButtonRef.classList.remove('hidden');
        if (pauseButtonRef) pauseButtonRef.classList.add('hidden');
    }
}

/**
 * Restarts the Day 1 game. This function is called from main2.js.
 * It's essentially a wrapper to trigger a full re-initialization of Day 1.
 */
export function restartDay1Game() {
    pauseDay1Game(); // Pause current game
    showMessageBoxRef('Restarting Day 1', 'Starting the sleigh service challenge from the beginning.', () => {
        restartCurrentGameRef(); // Trigger the main2.js function to re-init the current game
    }, 2000); // Shorter duration for restart message
}