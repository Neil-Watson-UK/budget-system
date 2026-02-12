/**
 * day3.js
 *
 * Contains the specific game logic for Day 3: New Open Office (Platformer Game).
 * This module is designed to be imported and controlled by main2.js.
 *
 * @version 1.0.5
 * @date 2025-07-21
 * @author Gemini Assistant
 */

// Define a global object to hold Day3Game functions immediately
window.Day3Game = window.Day3Game || {};
console.log("window.Day3Game namespace defined and accessible.");

// --- Game State Variables ---
// Moved global declarations for gameRunning, animationFrameId, lastFrameTime into window.Day3Game
window.Day3Game.gameRunning = false;
window.Day3Game.animationFrameId = null;
window.Day3Game.lastFrameTime = 0; // For delta time calculation

// Player properties
let player = {
    x: 0,
    y: 0,
    width: 40,
    height: 60,
    velX: 0,
    velY: 0,
    speed: 200, // pixels per second
    jumpForce: 450, // pixels per second
    gravity: 800, // pixels per second squared
    onGround: false,
    headsetLevelIndex: 0, // Current index in HEADSET_LEVELS
    tokensCollectedForUpgrade: 0, // Tokens collected towards next headset upgrade
    isInvincible: false, // For temporary invincibility after hitting noise
    invincibilityDuration: 1500, // 1.5 seconds
    invincibilityEndTime: 0,
    blinkToggle: false // For invincibility visual feedback
};

// Game elements
let platforms = [];
let tokens = [];
let noiseObjects = [];

// Game progression and scoring
const HEADSET_LEVELS = [
    { name: "IMPACT 100", tokensNeeded: 0 },
    { name: "IMPACT 400", tokensNeeded: 5 },
    { name: "IMPACT 700", tokensNeeded: 15 },
    { name: "IMPACT 800", tokensNeeded: 30 },
    { name: "IMPACT 1000", tokensNeeded: 50 } // Final level
];
let totalTokensCollected = 0; // Total tokens collected across all upgrades
let gameScore = 0; // The actual score displayed

// Game timing and spawning
let platformSpawnY = 0; // Y-coordinate for spawning new platforms (relative to player's highest point)
const PLATFORM_GAP_Y_MIN = 80; // Min vertical gap between platforms
const PLATFORM_GAP_Y_MAX = 150; // Max vertical gap between platforms
const PLATFORM_WIDTH_MIN = 100;
const PLATFORM_WIDTH_MAX = 200;
const INITIAL_PLATFORMS = 10; // Number of platforms to generate at start

const TOKEN_SPAWN_CHANCE = 0.7; // 70% chance a platform spawns a token
const NOISE_SPAWN_INTERVAL_MIN = 2000; // Min ms between noise spawns
const NOISE_SPAWN_INTERVAL_MAX = 5000; // Max ms between noise spawns
let lastNoiseSpawnTime = 0;

const GAME_WIN_HEADSET_INDEX = HEADSET_LEVELS.length - 1; // Index of the top headset

// Moving platform specific constants
const MOVING_PLATFORM_CHANCE = 0.3; // 30% chance a platform is moving
const MOVING_PLATFORM_SPEED = 80; // pixels per second
const MOVING_PLATFORM_RANGE = 100; // pixels it moves left/right from its start position

// Input handling
let keys = {
    ArrowLeft: false,
    ArrowRight: false,
    Space: false
};

// --- Game Object Classes ---

/**
 * Represents a static platform in the game.
 */
class Platform {
    constructor(x, y, width, height) {
        this.x = x;
        this.y = y;
        this.width = width;
        this.height = height;
    }

    draw(ctx) {
        ctx.fillStyle = '#654321'; // Brown for platforms
        ctx.fillRect(this.x, this.y, this.width, this.height);
        ctx.strokeStyle = '#333333';
        ctx.lineWidth = 2;
        ctx.strokeRect(this.x, this.y, this.width, this.height);
    }

    update(deltaTime) {
        // Static platforms don't move
    }
}

/**
 * Represents a moving platform in the game.
 * Inherits from Platform.
 */
class MovingPlatform extends Platform {
    constructor(x, y, width, height, moveRange, moveSpeed) {
        super(x, y, width, height);
        this.startX = x; // Original X position
        this.moveRange = moveRange; // How far it moves left/right from startX
        this.moveSpeed = moveSpeed; // Speed of movement
        this.direction = 1; // 1 for right, -1 for left
    }

    update(deltaTime) {
        this.x += this.direction * this.moveSpeed * deltaTime;

        // Reverse direction if it hits the bounds of its movement range
        if (this.direction === 1 && this.x >= this.startX + this.moveRange) {
            this.direction = -1;
            this.x = this.startX + this.moveRange; // Snap to boundary to prevent overshooting
        } else if (this.direction === -1 && this.x <= this.startX - this.moveRange) {
            this.direction = 1;
            this.x = this.startX - this.moveRange; // Snap to boundary
        }
    }

    draw(ctx) {
        // Draw a distinct color for moving platforms
        ctx.fillStyle = '#8B0000'; // Dark Red for moving platforms
        ctx.fillRect(this.x, this.y, this.width, this.height);
        ctx.strokeStyle = '#333333';
        ctx.lineWidth = 2;
        ctx.strokeRect(this.x, this.y, this.width, this.height);
        // Add an arrow or indicator for movement
        ctx.fillStyle = '#FFFFFF';
        ctx.font = '16px Arial';
        ctx.textAlign = 'center';
        ctx.fillText(this.direction === 1 ? '→' : '←', this.x + this.width / 2, this.y + this.height / 2 + 5);
    }
}


/**
 * Represents a BrainAdapt Token.
 */
class Token {
    constructor(x, y, value = 1) {
        this.x = x;
        this.y = y;
        this.width = 20;
        this.height = 20;
        this.value = value; // How many tokens it counts as
        this.collected = false;
        this.rotation = 0; // For spinning effect
    }

    draw(ctx) {
        if (this.collected) return;

        ctx.save(); // Save the current canvas state
        ctx.translate(this.x + this.width / 2, this.y + this.height / 2); // Move origin to center of token
        ctx.rotate(this.rotation); // Apply rotation

        // Draw the 'B' token (more stylized)
        ctx.fillStyle = '#FFD700'; // Gold for tokens
        ctx.beginPath();
        ctx.arc(0, 0, this.width / 2, 0, Math.PI * 2);
        ctx.fill();

        ctx.strokeStyle = '#DAA520'; // Darker gold border
        ctx.lineWidth = 2;
        ctx.stroke();

        ctx.fillStyle = '#000000'; // Black text
        ctx.font = 'bold 16px Inter'; // Larger, bolder font
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('B', 0, 2); // 'B' for BrainAdapt, slightly adjusted Y for visual centering

        ctx.restore(); // Restore the canvas state
    }

    update(deltaTime) {
        this.rotation += 2 * Math.PI * deltaTime; // Spin at 1 rotation per second
        if (this.rotation > Math.PI * 2) {
            this.rotation -= Math.PI * 2;
        }
    }
}

/**
 * Represents falling noise obstacles.
 */
class Noise {
    constructor(x, y, velY) {
        this.x = x;
        this.y = y;
        this.width = 30;
        this.height = 30;
        this.velY = velY; // Falling speed
        this.phase = Math.random() * Math.PI * 2; // For chaotic movement
    }

    draw(ctx) {
        ctx.save();
        ctx.translate(this.x + this.width / 2, this.y + this.height / 2);

        // Draw a jagged, chaotic shape for noise
        ctx.fillStyle = '#FF0000'; // Bright red for noise
        ctx.beginPath();
        ctx.moveTo(0, -this.height / 2);
        ctx.lineTo(this.width / 2, -this.height / 4);
        ctx.lineTo(this.width / 4, this.height / 2);
        ctx.lineTo(-this.width / 4, this.height / 2);
        ctx.lineTo(-this.width / 2, -this.height / 4);
        ctx.closePath();
        ctx.fill();

        ctx.strokeStyle = '#8B0000'; // Dark red border
        ctx.lineWidth = 3;
        ctx.stroke();

        ctx.fillStyle = '#FFFFFF';
        ctx.font = 'bold 20px Inter'; // Larger, bolder exclamation mark
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('!', 0, 2);

        ctx.restore();
    }

    update(deltaTime) {
        // Add subtle horizontal oscillation for more chaotic movement
        this.x += Math.sin(this.phase + performance.now() * 0.005) * 20 * deltaTime; // Oscillate horizontally
        this.phase += deltaTime; // Advance phase for continuous oscillation
    }
}

// --- Game Logic Functions ---

/**
 * Generates a new platform, which can be static or moving.
 * @param {number} currentMaxY The highest Y-coordinate of an existing platform.
 * @returns {Platform|MovingPlatform} A new platform object.
 */
function generatePlatform(currentMaxY) {
    const width = Math.random() * (PLATFORM_WIDTH_MAX - PLATFORM_WIDTH_MIN) + PLATFORM_WIDTH_MIN;
    const height = 20;
    const x = Math.random() * (window.Day3Game.canvas.width - width); // Use window.Day3Game.canvas
    const y = currentMaxY - (Math.random() * (PLATFORM_GAP_Y_MAX - PLATFORM_GAP_Y_MIN) + PLATFORM_GAP_Y_MIN);

    if (Math.random() < MOVING_PLATFORM_CHANCE) {
        // Ensure moving platforms don't spawn too close to edges
        const safeX = Math.max(MOVING_PLATFORM_RANGE, Math.min(x, window.Day3Game.canvas.width - width - MOVING_PLATFORM_RANGE));
        return new MovingPlatform(safeX, y, width, height, MOVING_PLATFORM_RANGE, MOVING_PLATFORM_SPEED);
    } else {
        return new Platform(x, y, width, height);
    }
}

/**
 * Generates initial platforms for the game.
 */
function generateInitialPlatforms() {
    platforms = [];
    tokens = []; // Clear tokens as well
    noiseObjects = []; // Clear noise objects as well

    // Start with a ground platform
    platforms.push(new Platform(0, window.Day3Game.canvas.height - 50, window.Day3Game.canvas.width, 50)); // Use window.Day3Game.canvas

    let currentY = window.Day3Game.canvas.height - 50; // Use window.Day3Game.canvas
    for (let i = 0; i < INITIAL_PLATFORMS; i++) {
        const newPlatform = generatePlatform(currentY);
        platforms.push(newPlatform);
        currentY = newPlatform.y; // Set currentY to the new platform's Y for next spawn
        // Potentially spawn a token on the new platform
        if (Math.random() < TOKEN_SPAWN_CHANCE) {
            tokens.push(new Token(newPlatform.x + newPlatform.width / 2 - 10, newPlatform.y - 30));
        }
    }
    platformSpawnY = currentY; // Set the highest platform Y for continuous generation
}

/**
 * Checks for collision between two rectangles (AABB).
 * @param {object} rect1
 * @param {object} rect2
 * @returns {boolean} True if colliding, false otherwise.
 */
function checkCollision(rect1, rect2) {
    return rect1.x < rect2.x + rect2.width &&
           rect1.x + rect1.width > rect2.x &&
           rect1.y < rect2.y + rect2.height &&
           rect1.y + rect1.height > rect2.y;
}

/**
 * Updates the player's headset level based on collected tokens.
 */
function updateHeadset() {
    if (player.headsetLevelIndex < HEADSET_LEVELS.length - 1) {
        const nextLevel = HEADSET_LEVELS[player.headsetLevelIndex + 1];
        if (player.tokensCollectedForUpgrade >= nextLevel.tokensNeeded) {
            player.headsetLevelIndex++;
            player.tokensCollectedForUpgrade = 0; // Reset for next upgrade
            // Use the passed displayGameStatusRef
            window.Day3Game.displayGameStatusRef(`Headset Upgraded! Now: ${HEADSET_LEVELS[player.headsetLevelIndex].name}!`, 2000);

            // Check for win condition
            if (player.headsetLevelIndex === GAME_WIN_HEADSET_INDEX) {
                endGame(true); // Win condition met
            }
        }
    }
}

/**
 * Initializes the Day 3 game.
 * @param {HTMLCanvasElement} canvasElement - The canvas DOM element.
 * @param {CanvasRenderingContext2D} context - The 2D rendering context.
 * @param {function} messageBoxFunction - Reference to main2.js's showMessageBox.
 * @param {function} displayGameStatusFunction - Reference to main2.js's displayGameStatus.
 * @param {function} updateScoreFunction - Function to update the global score in main2.js.
 * @param {function} restartGameFunction - Function to restart the current game in main2.js.
 * @param {function} getGlobalScoreFunction - Function to get the current global score from main2.js.
 * @param {HTMLElement} currentTokensDisplayParam - Not used in Day 3, but kept for consistent API.
 * @param {HTMLElement} sackCapacityDisplayParam - Not used in Day 3, but kept for consistent API.
 * @param {HTMLElement} changeSackButtonParam - Not used in Day 3, but kept for consistent API.
 * @param {HTMLElement} startButtonRefParam - Reference to the global start button.
 * @param {HTMLElement} pauseButtonRefParam - Reference to the global pause button.
 * @param {HTMLElement} restartButtonRefParam - Reference to the global restart button.
 * @param {object} playerConfig - Player details including superpower.
 * @param {number} levelInfo - Current game level.
 */
window.Day3Game.initDay3Game = function(canvasElement, context, messageBoxFunction, displayGameStatusFunction, updateScoreFunction, restartGameFunction, getGlobalScoreFunction, currentTokensDisplayParam, sackCapacityDisplayParam, changeSackButtonParam, startButtonRefParam, pauseButtonRefParam, restartButtonRefParam, playerConfig, levelInfo) {
    console.log("Day 3 New Open Office Game Initializing...");
    window.Day3Game.canvas = canvasElement; // Assign to property
    window.Day3Game.ctx = context;    // Assign to property
    // Assign passed functions to local variables for use within this module's scope
    window.Day3Game.showMessageBoxRef = messageBoxFunction;
    window.Day3Game.displayGameStatusRef = displayGameStatusFunction;
    window.Day3Game.updateGlobalScoreRef = updateScoreFunction;
    window.Day3Game.restartCurrentGameRef = restartGameFunction;
    window.Day3Game.getGlobalScoreRef = getGlobalScoreFunction;

    // Hide Day 1/2 specific UI elements if they exist
    if (currentTokensDisplayParam && currentTokensDisplayParam.parentElement) currentTokensDisplayParam.parentElement.classList.add('hidden');
    if (sackCapacityDisplayParam && sackCapacityDisplayParam.parentElement) sackCapacityDisplayParam.parentElement.classList.add('hidden');
    if (changeSackButtonParam) changeSackButtonParam.classList.add('hidden');

    // Apply superpower effects (e.g., player speed, jump force, token value, noise frequency)
    // This part can be expanded based on how superpowers should affect the platformer
    switch (playerConfig.superpower) {
        case 'IMPACT':
            player.speed = 250; // Faster movement
            player.jumpForce = 500; // Higher jump
            // Maybe noise falls slower or is less frequent
            break;
        case 'ADAPT':
            player.gravity = 600; // Lower gravity, easier jumps
            // Maybe tokens are worth more
            break;
        case 'EXPAND':
            // Maybe more tokens spawn, or game area feels larger
            break;
        default:
            // Default values already set
            break;
    }

    // Set canvas dimensions dynamically
    window.Day3Game.canvas.width = window.Day3Game.canvas.parentElement.clientWidth; // Use window.Day3Game.canvas
    window.Day3Game.canvas.height = window.Day3Game.canvas.parentElement.clientHeight; // Use window.Day3Game.canvas
    window.addEventListener('resize', onWindowResize);

    window.Day3Game.resetDay3Game(); // Call reset via the global object
    console.log("Day 3 New Open Office Game Initialized with superpower:", playerConfig.superpower);
};

/**
 * Resets the game state for Day 3.
 */
window.Day3Game.resetDay3Game = function() { // Exposed this directly
    // Reset player state
    player.x = window.Day3Game.canvas.width / 2 - player.width / 2; // Use window.Day3Game.canvas
    player.y = window.Day3Game.canvas.height - player.height - 50; // Start on the bottom platform // Use window.Day3Game.canvas
    player.velX = 0;
    player.velY = 0;
    player.onGround = false;
    player.headsetLevelIndex = 0;
    player.tokensCollectedForUpgrade = 0;
    totalTokensCollected = 0;
    gameScore = 0;
    player.isInvincible = false;
    player.invincibilityEndTime = 0;
    player.blinkToggle = false;

    // Clear game elements
    platforms = [];
    tokens = [];
    noiseObjects = [];

    // Generate initial platforms
    generateInitialPlatforms();

    window.Day3Game.lastFrameTime = 0;
    window.Day3Game.gameRunning = false;
    if (window.Day3Game.animationFrameId) {
        cancelAnimationFrame(window.Day3Game.animationFrameId);
    }
    removeInputListeners(); // Ensure listeners are removed on reset
    window.Day3Game.updateGlobalScoreRef(0); // Reset global score display
    drawGame(); // Draw initial state
};

/**
 * Starts the Day 3 game loop.
 */
window.Day3Game.startGame = function() {
    if (window.Day3Game.gameRunning) return;
    window.Day3Game.gameRunning = true;
    addInputListeners(); // Add input listeners when game starts
    window.Day3Game.showMessageBoxRef('Day 3: New Open Office', `Your task is to deliver better audio to your customers!
        <br><br>Jump up through the building collecting <span style="color: #FFD700;">BrainAdapt tokens</span> to get an upgraded headset.
        <br>Current Headset: <b>${HEADSET_LEVELS[player.headsetLevelIndex].name}</b>
        <br>Next Upgrade at: <b>${HEADSET_LEVELS[player.headsetLevelIndex + 1] ? HEADSET_LEVELS[player.headsetLevelIndex + 1].tokensNeeded : 'N/A'} tokens</b>
        <br><br>Use <b>Arrow Left/Right</b> to move horizontally.
        <br>Press <b>Spacebar</b> to jump.
        <br><br>Avoid the <span style="color: #B22222;">noise</span> that falls from the ceiling! Good luck!`, () => {
        window.Day3Game.lastFrameTime = performance.now(); // Initialize for the game loop
        lastNoiseSpawnTime = performance.now(); // Initialize noise spawn timer
        window.Day3Game.animationFrameId = requestAnimationFrame(gameLoop);
    });
    console.log("Day 3 New Open Office Game Started.");
};

/**
 * Pauses the Day 3 game.
 */
window.Day3Game.pauseGame = function() {
    if (!window.Day3Game.gameRunning) return;
    window.Day3Game.gameRunning = false;
    if (window.Day3Game.animationFrameId) {
        cancelAnimationFrame(window.Day3Game.animationFrameId);
    }
    removeInputListeners(); // Remove input listeners when game pauses
    window.Day3Game.showMessageBoxRef('Game Paused', 'The Open Office Game is on hold. Click OK to resume.', () => {
        window.Day3Game.lastFrameTime = performance.now(); // Reset lastFrameTime to prevent time jump
        window.Day3Game.animationFrameId = requestAnimationFrame(gameLoop);
    });
    console.log("Day 3 New Open Office Game Paused.");
};

/**
 * Restarts the Day 3 game.
 */
window.Day3Game.restartGame = function() {
    window.Day3Game.pauseGame(); // Pause current game
    window.Day3Game.showMessageBoxRef('Restarting Day 3', 'Starting the New Open Office challenge from the beginning.', () => {
        window.Day3Game.resetDay3Game(); // Full reset
        window.Day3Game.startGame();
    }, null, 2000, 'modal');
};

/**
 * The main game loop for Day 3.
 * @param {number} currentTime The current time in milliseconds.
 */
function gameLoop(currentTime) {
    if (!window.Day3Game.gameRunning) return;

    const deltaTime = (currentTime - window.Day3Game.lastFrameTime) / 1000; // Convert to seconds
    window.Day3Game.lastFrameTime = currentTime;

    updateGame(deltaTime, currentTime); // Pass currentTime for invincibility blinking
    drawGame(currentTime); // Pass currentTime for invincibility blinking

    window.Day3Game.animationFrameId = requestAnimationFrame(gameLoop);
}

/**
 * Updates game state for Day 3.
 * @param {number} deltaTime Time elapsed since last frame in seconds.
 * @param {number} currentTime Current time in milliseconds.
 */
function updateGame(deltaTime, currentTime) {
    // Player horizontal movement
    if (keys.ArrowLeft) {
        player.velX = -player.speed;
    } else if (keys.ArrowRight) {
        player.velX = player.speed;
    } else {
        player.velX = 0;
    }

    // Apply gravity
    player.velY += player.gravity * deltaTime;

    // Update player position
    player.x += player.velX * deltaTime;
    player.y += player.velY * deltaTime;

    // Keep player within horizontal bounds
    if (player.x < 0) player.x = 0;
    if (player.x + player.width > window.Day3Game.canvas.width) player.x = window.Day3Game.canvas.width - player.width; // Use window.Day3Game.canvas

    // Update platforms (especially moving ones)
    platforms.forEach(platform => {
        platform.update(deltaTime);
    });

    // Platform collision
    player.onGround = false;
    platforms.forEach(platform => {
        if (checkCollision(player, platform)) {
            // If falling and lands on platform
            if (player.velY > 0 && player.y + player.height <= platform.y + player.velY * deltaTime) {
                player.y = platform.y - player.height;
                player.velY = 0;
                player.onGround = true;

                // If on a moving platform, move player with it
                if (platform instanceof MovingPlatform) {
                    player.x += platform.direction * platform.moveSpeed * deltaTime;
                    // Ensure player stays within canvas bounds even when on moving platform
                    if (player.x < 0) player.x = 0;
                    if (player.x + player.width > window.Day3Game.canvas.width) player.x = window.Day3Game.canvas.width - player.width;
                }
            }
        }
    });

    // Handle jumping
    if (keys.Space && player.onGround) {
        player.velY = -player.jumpForce;
        player.onGround = false;
        keys.Space = false; // Consume space key press
    }

    // Game over if player falls off screen
    if (player.y > window.Day3Game.canvas.height) { // Use window.Day3Game.canvas
        endGame(false); // Player lost
        return;
    }

    // Camera/World scrolling (when player moves up)
    const scrollThreshold = window.Day3Game.canvas.height * 0.4; // When player is above 40% of screen height // Use window.Day3Game.canvas
    if (player.y < scrollThreshold) {
        const scrollAmount = scrollThreshold - player.y;
        player.y = scrollThreshold; // Keep player at threshold

        // Scroll all elements down
        platforms.forEach(p => p.y += scrollAmount);
        tokens.forEach(t => t.y += scrollAmount);
        noiseObjects.forEach(n => n.y += scrollAmount);
        platformSpawnY += scrollAmount; // Adjust spawn point

        // Generate new platforms as player moves up
        while (platformSpawnY > 0) {
            const newPlatform = generatePlatform(platformSpawnY);
            platforms.push(newPlatform);
            platformSpawnY = newPlatform.y;
            if (Math.random() < TOKEN_SPAWN_CHANCE) {
                tokens.push(new Token(newPlatform.x + newPlatform.width / 2 - 10, newPlatform.y - 30));
            }
        }
    }

    // Update noise objects
    noiseObjects.forEach(noise => {
        noise.y += noise.velY * deltaTime;
        noise.update(deltaTime); // Update noise for chaotic movement
    });

    // Update tokens
    tokens.forEach(token => {
        token.update(deltaTime); // Update token for spinning
    });

    // Spawn new noise objects
    if (currentTime - lastNoiseSpawnTime > Math.random() * (NOISE_SPAWN_INTERVAL_MAX - NOISE_SPAWN_INTERVAL_MIN) + NOISE_SPAWN_INTERVAL_MIN) {
        const x = Math.random() * (window.Day3Game.canvas.width - 30); // Use window.Day3Game.canvas
        const y = -30; // Spawn just above canvas
        const velY = 100 + Math.random() * 150; // Random falling speed
        noiseObjects.push(new Noise(x, y, velY));
        lastNoiseSpawnTime = currentTime;
    }

    // Collision detection: Player vs. Tokens
    tokens.forEach(token => {
        if (!token.collected && checkCollision(player, token)) {
            token.collected = true;
            player.tokensCollectedForUpgrade += token.value;
            totalTokensCollected += token.value;
            gameScore += token.value * 10; // Each token adds 10 points to game score
            window.Day3Game.updateGlobalScoreRef(gameScore); // Update global score in main2.js
            window.Day3Game.displayGameStatusRef(`Collected BrainAdapt Token! (+${token.value})`, 1000);
            updateHeadset(); // Check for headset upgrade
        }
    });

    // Collision detection: Player vs. Noise
    if (!player.isInvincible) {
        noiseObjects.forEach(noise => {
            if (checkCollision(player, noise)) {
                window.Day3Game.displayGameStatusRef('Hit by Noise! Temporary invincibility.', 1500);
                player.isInvincible = true;
                player.invincibilityEndTime = currentTime + player.invincibilityDuration;
                // Optionally, apply a small penalty or knockback
                gameScore = Math.max(0, gameScore - 50); // Small score penalty
                window.Day3Game.updateGlobalScoreRef(gameScore);
            }
        });
    } else {
        // Handle invincibility timer
        if (currentTime > player.invincibilityEndTime) {
            player.isInvincible = false;
            player.blinkToggle = false; // Ensure player is visible
        } else {
            // Toggle blinking effect during invincibility
            player.blinkToggle = Math.floor(currentTime / 100) % 2 === 0;
        }
    }


    // Remove off-screen elements
    platforms = platforms.filter(p => p.y < window.Day3Game.canvas.height + p.height); // Use window.Day3Game.canvas
    tokens = tokens.filter(t => !t.collected && t.y < window.Day3Game.canvas.height + t.height); // Use window.Day3Game.canvas
    noiseObjects = noiseObjects.filter(n => n.y < window.Day3Game.canvas.height + n.height); // Use window.Day3Game.canvas
}

/**
 * Draws game elements for Day 3.
 * @param {number} currentTime Current time in milliseconds for invincibility blinking.
 */
function drawGame(currentTime) {
    // Use window.Day3Game.ctx and window.Day3Game.canvas
    if (!window.Day3Game.ctx || !window.Day3Game.canvas) return;
    window.Day3Game.ctx.clearRect(0, 0, window.Day3Game.canvas.width, window.Day3Game.canvas.height); // Clear canvas

    // Draw background (simple office building feel)
    window.Day3Game.ctx.fillStyle = '#ADD8E6'; // Light blue for sky/background
    window.Day3Game.ctx.fillRect(0, 0, window.Day3Game.canvas.width, window.Day3Game.canvas.height);

    // Draw building structure (vertical lines)
    window.Day3Game.ctx.strokeStyle = '#AAAAAA'; // Grey for building lines
    window.Day3Game.ctx.lineWidth = 2;
    for (let i = 0; i < window.Day3Game.canvas.width; i += 100) { // Use window.Day3Game.canvas
        window.Day3Game.ctx.beginPath();
        window.Day3Game.ctx.moveTo(i, 0);
        window.Day3Game.ctx.lineTo(i, window.Day3Game.canvas.height); // Use window.Day3Game.canvas
        window.Day3Game.ctx.stroke();
    }
    for (let i = 0; i < window.Day3Game.canvas.height; i += 100) { // Use window.Day3Game.canvas
        window.Day3Game.ctx.beginPath();
        window.Day3Game.ctx.moveTo(0, i);
        window.Day3Game.ctx.lineTo(window.Day3Game.canvas.width, i); // Use window.Day3Game.canvas
        window.Day3Game.ctx.stroke();
    }

    // Draw simple windows on the background
    window.Day3Game.ctx.fillStyle = 'rgba(173, 216, 230, 0.7)'; // Lighter blue for window glass
    window.Day3Game.ctx.strokeStyle = '#666666'; // Darker grey for window frames
    window.Day3Game.ctx.lineWidth = 1;
    for (let x = 50; x < window.Day3Game.canvas.width - 50; x += 150) {
        for (let y = 50; y < window.Day3Game.canvas.height - 150; y += 150) {
            window.Day3Game.ctx.fillRect(x, y, 40, 60);
            window.Day3Game.ctx.strokeRect(x, y, 40, 60);
            // Add a cross for window panes
            window.Day3Game.ctx.beginPath();
            window.Day3Game.ctx.moveTo(x + 20, y);
            window.Day3Game.ctx.lineTo(x + 20, y + 60);
            window.Day3Game.ctx.moveTo(x, y + 30);
            window.Day3Game.ctx.lineTo(x + 40, y + 30);
            window.Day3Game.ctx.stroke();
        }
    }


    // Draw platforms
    platforms.forEach(p => p.draw(window.Day3Game.ctx)); // Pass ctx to draw method

    // Draw tokens
    tokens.forEach(t => t.draw(window.Day3Game.ctx)); // Pass ctx to draw method

    // Draw noise objects
    noiseObjects.forEach(n => n.draw(window.Day3Game.ctx)); // Pass ctx to draw method

    // Draw player (with invincibility blinking)
    if (!player.isInvincible || player.blinkToggle) {
        // Draw a more elf-like character
        window.Day3Game.ctx.fillStyle = '#007BFF'; // Blue for player body
        window.Day3Game.ctx.fillRect(player.x, player.y + player.height * 0.2, player.width, player.height * 0.8); // Body
        window.Day3Game.ctx.fillStyle = '#FFD700'; // Gold for elf hat
        window.Day3Game.ctx.beginPath();
        window.Day3Game.ctx.moveTo(player.x + player.width / 2, player.y);
        window.Day3Game.ctx.lineTo(player.x + player.width, player.y + player.height * 0.3);
        window.Day3Game.ctx.lineTo(player.x, player.y + player.height * 0.3);
        window.Day3Game.ctx.closePath();
        window.Day3Game.ctx.fill();

        window.Day3Game.ctx.fillStyle = '#FFC0CB'; // Pink for face
        window.Day3Game.ctx.beginPath();
        window.Day3Game.ctx.arc(player.x + player.width / 2, player.y + player.height * 0.2, player.width * 0.2, 0, Math.PI * 2);
        window.Day3Game.ctx.fill();

        // Draw headset on player (more detailed)
        window.Day3Game.ctx.fillStyle = '#333333'; // Dark grey for headset band
        window.Day3Game.ctx.fillRect(player.x + player.width / 4, player.y + player.height * 0.15, player.width / 2, 5); // Headband
        window.Day3Game.ctx.fillRect(player.x + player.width / 4 - 5, player.y + player.height * 0.15 - 10, 5, 20); // Left earcup
        window.Day3Game.ctx.fillRect(player.x + player.width * 0.75, player.y + player.height * 0.15 - 10, 5, 20); // Right earcup
    }

    // Draw UI
    window.Day3Game.ctx.fillStyle = 'white';
    window.Day3Game.ctx.font = 'bold 24px Inter';
    window.Day3Game.ctx.textAlign = 'left';
    window.Day3Game.ctx.fillText(`Score: ${Math.floor(gameScore)}`, 20, 40);
    window.Day3Game.ctx.fillText(`Headset: ${HEADSET_LEVELS[player.headsetLevelIndex].name}`, 20, 70);

    if (player.headsetLevelIndex < HEADSET_LEVELS.length - 1) {
        const nextLevel = HEADSET_LEVELS[player.headsetLevelIndex + 1];
        window.Day3Game.ctx.fillText(`Next Upgrade: ${player.tokensCollectedForUpgrade}/${nextLevel.tokensNeeded} tokens`, 20, 100);
    } else {
        window.Day3Game.ctx.fillText('Headset: MAX LEVEL!', 20, 100);
    }
}

/**
 * Ends the Day 3 game.
 * @param {boolean} won True if the player won, false otherwise.
 */
function endGame(won) {
    window.Day3Game.gameRunning = false;
    if (window.Day3Game.animationFrameId) {
        cancelAnimationFrame(window.Day3Game.animationFrameId);
    }
    removeInputListeners(); // Remove input listeners when game ends

    let messageTitle = won ? 'Quest Complete!' : 'Game Over!';
    let messageContent = won ?
        `Congratulations! You've reached the top headset: <b>${HEADSET_LEVELS[GAME_WIN_HEADSET_INDEX].name}</b>!
        <br><br>Your final score for Day 3: ${Math.floor(gameScore)}
        <br><br><span style="font-size: 28px;">The code to enter today's prize draw is: <b>IMPACT 7000 ANC</b></span>` :
        `Oh no! You couldn't deliver better audio.
        <br><br>Your final score for Day 3: ${Math.floor(gameScore)}
        <br><br><span style="font-size: 28px;">The code to enter today's prize draw is: <b>IMPACT 7000 ANC</b></span>`;

    window.Day3Game.showMessageBoxRef(messageTitle, messageContent, () => {
        if (won) {
            window.advanceToNextLevel(); // Call global function to advance to next day
        } else {
            // Stay on game screen, allow restart
            console.log("Game Over message dismissed. Player can restart.");
        }
    }, 0, 'modal');
}

// --- Input Handling ---
function addInputListeners() {
    document.addEventListener('keydown', handleKeyDown);
    document.addEventListener('keyup', handleKeyUp);
    console.log("Day 3 input listeners added.");
}

function removeInputListeners() {
    document.removeEventListener('keydown', handleKeyDown);
    document.removeEventListener('keyup', handleKeyUp);
    console.log("Day 3 input listeners removed.");
}

function handleKeyDown(e) {
    if (!window.Day3Game.gameRunning) return;
    if (e.key === 'ArrowLeft') {
        keys.ArrowLeft = true;
    } else if (e.key === 'ArrowRight') {
        keys.ArrowRight = true;
    } else if (e.key === ' ') {
        keys.Space = true;
    }
}

function handleKeyUp(e) {
    if (!window.Day3Game.gameRunning) return;
    if (e.key === 'ArrowLeft') {
        keys.ArrowLeft = false;
    } else if (e.key === 'ArrowRight') {
        keys.ArrowRight = false;
    } else if (e.key === ' ') {
        // Only set to false after jump is initiated, not just on key up
        // This allows for single jump per press
        // keys.Space = false; // This line should be managed in updateGame for jump logic
    }
}

// --- Window Resize Handler ---
function onWindowResize() {
    // Use window.Day3Game.canvas
    if (window.Day3Game.canvas && window.Day3Game.canvas.parentElement) {
        window.Day3Game.canvas.width = window.Day3Game.canvas.parentElement.clientWidth;
        window.Day3Game.canvas.height = window.Day3Game.canvas.parentElement.clientHeight;
        // Player position needs to be re-adjusted relative to new canvas size
        // For simplicity, we can just reset player to bottom center on resize
        player.x = window.Day3Game.canvas.width / 2 - player.width / 2;
        player.y = window.Day3Game.canvas.height - player.height - 50;
        // Re-generate platforms based on new dimensions if needed, or adjust existing ones
        // For this game, new platforms are generated as player moves up, so existing ones
        // will just be drawn relative to their current positions.
    }
}

// Expose the internal reset function to the global Day3Game object
// window.Day3Game.resetDay3Game = window.Day3Game.resetDay3Game; // This line was redundant and potentially problematic
// The function is already directly assigned to window.Day3Game.resetDay3Game above.

console.log("Day3.js script loaded.");