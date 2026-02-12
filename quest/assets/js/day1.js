/**
 * day1.js
 *
 * Contains the specific game logic for Day 1: Santa's Sleigh Service.
 * This module is designed to be imported and controlled by main2.js.
 *
 * @version 1.0.45
 * @date 2025-07-21
 * @author Gemini Assistant
 */

// Define a global object to hold Day1Game functions immediately
window.Day1Game = window.Day1Game || {};
console.log("window.Day1Game namespace defined and accessible."); // Debugging log

// Wrap the entire script in an IIFE to prevent global variable conflicts
(function() {
    // --- Internal Game State for Day 1 ---
    let canvas;
    let ctx;
    let showMessageBoxRef; // Reference to the showMessageBox function from main2.js
    let displayGameStatusRef; // Reference to the displayGameStatus function from main2.js
    let updateGlobalScoreRef; // Reference to the updateGlobalScore function from main2.js
    let restartCurrentGameRef; // Reference to function in main2.js to restart current day's game
    let getGlobalScoreRef; // Reference to function to get the current global score from main2.js

    let day1Player = {}; // Player object specific to Day 1, populated from main2.js
    let day1Level = 1; // The current level/environment within Day 1 (e.g., Office, Home, Cafe)

    let gameRunning = false;
    let animationFrameId;
    let lastFrameTime = 0; // For delta time calculation

    // Sleigh properties
    let sleigh = {
        x: 100, // Sleigh's X position on canvas
        y: 225, // Centered vertically initially - this will be adjusted based on canvas height
        width: 80,
        height: 50,
        speedY: 0, // Vertical movement speed
        maxSpeedY: 5, // Maximum vertical speed
        accelerationY: 0.2, // Vertical acceleration
        decelerationY: 0.1, // Vertical deceleration
        moveSpeedX: 4, // How fast sleigh moves left/right on its own plane
        xBoundsLeft: 50, // Minimum X position
        xBoundsRight: 700, // Maximum X position (this should be relative to canvas width)
        yBoundsTop: 0, // Minimum Y position
        yBoundsBottom: 450 - 50, // Maximum Y position (canvas height - sleigh height) - this will be adjusted
        scrollSpeed: 3, // Base scrolling speed of the background and objects
        targetScrollSpeed: 3, // Speed sleigh tries to reach
        minScrollSpeed: 2, // Minimum scroll speed
        maxScrollSpeed: 5, // Maximum scroll speed
        acceleration: 0.05, // How quickly speed changes
        sackCapacity: 50, // Max tokens before needing to empty
        currentSackTokens: 0, // Tokens currently in the sack
        animationFrame: 0, // Current frame for sleigh animation
        animationSpeed: 0.1, // Speed of animation
        animationFrames: 4, // Number of animation frames
        image: new Image() // Sleigh image
    };
    sleigh.image.src = './assets/images/day1/sleigh.png'; // Path to sleigh sprite sheet

    // Game objects (obstacles and tokens)
    let obstacles = [];
    let tokens = [];

    // Game timing and spawning
    let obstacleSpawnInterval = 1500; // Milliseconds between obstacle spawns
    let tokenSpawnInterval = 800; // Milliseconds between token spawns
    let lastTokenSpawnTime = 0;
    let lastObstacleSpawnTime = 0;
    const INITIAL_SPAWN_DELAY = 3000; // 3 seconds delay before first obstacle/token spawns
    const INITIAL_SPAWN_OFFSET_X = 500; // Spawn objects this many pixels further right initially (Increased from 200)

    // Game configuration based on superpower - **Initialized with a default**
    let gameConfig = {
        sleighScrollSpeed: 3.0,
        sackCapacity: 50,
        tokenValues: [10, 20, 30]
    };

    // UI element references from main2.js
    let currentTokensDisplayRef;
    let sackCapacityDisplayRef;
    let changeSackButtonRef;
    let startButtonRef;
    let pauseButtonRef;
    let restartButtonRef;

    // Input handling
    let keys = {
        ArrowUp: false,
        ArrowDown: false,
        ArrowLeft: false,
        ArrowRight: false,
        c: false // For emptying sack
    };
    let cKeyHandled = false; // New flag to prevent continuous emptying when 'c' is held down

    // Difficulty and Timer Variables
    let difficultyLevel = 1;
    const DIFFICULTY_INCREASE_INTERVAL = 10000; // Increase difficulty every 10 seconds
    let lastDifficultyIncreaseTime = 0; // Timestamp when difficulty was last increased

    // Background scrolling variable - **Declared in main IIFE scope**
    let backgroundX = 0;

    // --- Game Assets (Images) ---
    const ASSET_PATHS = {
        sleigh: './assets/images/day1/sleigh.png', // Main sleigh sprite
        token10: './assets/images/day1/token10.png',
        token20: './assets/images/day1/token20.png',
        token30: './assets/images/day1/token30.png',
        comfortObstacle: './assets/images/day1/comfortObstacle.png',
        noiseObstacle: './assets/images/day1/noiseObstacle.png',
    };
    let assets = {}; // To store loaded Image objects

    /**
     * Draws a simple loading screen.
     */
    function drawLoadingScreen() {
        if (!ctx || !canvas || canvas.width === 0 || canvas.height === 0) return; // Only draw if canvas has dimensions
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#002B32'; // Dark petrol background
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.font = '24px Inter';
        ctx.fillStyle = '#FFFFFF'; // White text
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('Loading Sleigh Service Assets...', canvas.width / 2, canvas.height / 2);
    }

    /**
     * Loads all game assets (images).
     * @returns {Promise<void>} A promise that resolves when all assets are loaded.
     */
    const loadAssets = async function() {
        console.log("loadAssets: Starting asset loading.");
        const assetPromises = [];

        for (const key in ASSET_PATHS) {
            const img = new Image();
            img.src = ASSET_PATHS[key];
            const promise = new Promise((resolve, reject) => {
                img.onload = () => {
                    assets[key] = img;
                    console.log(`loadAssets: Successfully loaded ${ASSET_PATHS[key]}`);
                    resolve();
                };
                img.onerror = () => {
                    const errorMessage = `Failed to load image: ${ASSET_PATHS[key]}`;
                    console.error(`loadAssets: ${errorMessage}`);
                    reject(new Error(errorMessage)); // Reject the promise so Promise.all will catch it
                };
            });
            assetPromises.push(promise);
        }
        return Promise.all(assetPromises)
            .then(() => {
                console.log("loadAssets: All assets successfully loaded.");
            })
            .catch(error => {
                console.error("loadAssets: One or more assets failed to load:", error);
                throw error; // Re-throw to propagate the error to initDay1Game's catch block
            });
    };


    // --- Game Object Classes ---

    /**
     * Base class for game objects (obstacles, tokens).
     * @param {number} x
     * @param {number} y
     * @param {number} width
     * @param {number} height
     * @param {string} color
     */
    class GameObject {
        constructor(x, y, width, height, color) {
            this.x = x;
            this.y = y;
            this.width = width;
            this.height = height;
            this.color = color;
            this.markedForDeletion = false; // Flag for removal
            this.initialY = y; // Store initial Y for oscillation
        }

        draw(ctx) {
            ctx.fillStyle = this.color;
            ctx.fillRect(this.x, this.y, this.width, this.height);
        }

        update(deltaTime, scrollSpeed) {
            this.x -= scrollSpeed * (deltaTime / 16.67); // Adjust movement by delta time
        }
    }

    /**
     * Obstacle class.
     * @param {number} x
     * @param {number} y
     * @param {number} width
     * @param {number} height
     * @param {string} type - 'noise' or 'comfort'
     */
    class Obstacle extends GameObject {
        constructor(x, y, width, height, type) { // Added type parameter
            super(x, y, width, height, '#B22222'); // Festive Red
            this.type = type; // Store type
            // Assign image based on type
            this.image = (type === 'noise' ? assets.noiseObstacle : assets.comfortObstacle);
            // For subtle vertical movement
            this.amplitude = 5 + Math.random() * 5; // How much it wiggles up/down (range 5-10)
            this.frequency = 0.005 + Math.random() * 0.005; // How fast it wiggles (range 0.005-0.01)
            this.phaseOffset = Math.random() * Math.PI * 2; // Starting point in the sine wave
            console.log(`Obstacle created: type=${this.type}, x=${this.x}, y=${this.y}`); // Debugging creation
        }

        draw(ctx) {
            if (this.image && this.image.complete) {
                ctx.drawImage(this.image, this.x, this.y, this.width, this.height);
                // console.log(`Obstacle.draw: Drawing obstacle at x=${this.x}, y=${this.y}`);
            } else {
                console.warn("Obstacle.draw: Image not loaded, drawing fallback rectangle.");
                super.draw(ctx);
            }
        }
        update(deltaTime, scrollSpeed, currentTime) {
            super.update(deltaTime, scrollSpeed);
            // Use initialY from GameObject and apply oscillation
            this.y = this.initialY + this.amplitude * Math.sin(currentTime * this.frequency + this.phaseOffset);
        }
    }

    /**
     * Token class.
     * @param {number} x
     * @param {number} y
     * @param {number} width
     * @param {number} height
     * @param {number} value
     */
    class Token extends GameObject {
        constructor(x, y, width, height, value) {
            super(x, y, width, height, '#FFD700'); // Gold Accent
            this.value = value;
            this.image = assets[`token${value}`]; // **Safe assignment**
            // For subtle vertical movement
            this.amplitude = 3 + Math.random() * 3; // How much it wiggles up/down (range 3-6)
            this.frequency = 0.008 + Math.random() * 0.007; // How fast it wiggles (range 0.008-0.015)
            this.phaseOffset = Math.random() * Math.PI * 2; // Starting point in the sine wave
            console.log(`Token created: value=${this.value}, x=${this.x}, y=${this.y}`); // Debugging creation
        }

        draw(ctx) {
            if (this.image && this.image.complete) {
                ctx.drawImage(this.image, this.x, this.y, this.width, this.height);
                // console.log(`Token.draw: Drawing token at x=${this.x}, y=${this.y}`);
            } else {
                console.warn("Token.draw: Image not loaded, drawing fallback rectangle.");
                super.draw(ctx);
                ctx.fillStyle = '#000000'; // Black text
                ctx.font = '12px Arial';
                ctx.textAlign = 'center';
                ctx.fillText(this.value, this.x + this.width / 2, this.y + this.height / 2 + 4);
            }
        }
        update(deltaTime, scrollSpeed, currentTime) {
            super.update(deltaTime, scrollSpeed);
            // Use initialY from GameObject and apply oscillation
            this.y = this.initialY + this.amplitude * Math.sin(currentTime * this.frequency + this.phaseOffset);
        }
    }

    // --- Game Logic Functions ---

    /**
     * Updates the current tokens and sack capacity display.
     */
    function updateTokenDisplays() {
        console.log("updateTokenDisplays called. Current Tokens:", sleigh.currentSackTokens, "Sack Capacity:", sleigh.sackCapacity);
        if (currentTokensDisplayRef) {
            currentTokensDisplayRef.textContent = `${sleigh.currentSackTokens}`;
            // Ensure visibility of the container when tokens are updated
            const container = document.getElementById('currentTokensDisplayContainer');
            if (container) container.classList.remove('hidden');
        } else {
            console.warn("currentTokensDisplayRef is null or undefined. Cannot update token display.");
        }
        if (sackCapacityDisplayRef) {
            sackCapacityDisplayRef.textContent = `${sleigh.sackCapacity}`;
            // Ensure visibility of the container when sack capacity is updated
            const container = document.getElementById('sackCapacityDisplayContainer');
            if (container) container.classList.remove('hidden');
        } else {
            console.warn("sackCapacityDisplayRef is null or undefined. Cannot update sack capacity display.");
        }
        // Ensure changeSackButtonRef is checked before accessing its classList
        if (changeSackButtonRef) {
            if (sleigh.currentSackTokens >= sleigh.sackCapacity) {
                changeSackButtonRef.classList.remove('hidden');
                changeSackButtonRef.classList.add('flash-button'); // Add a visual cue
            } else {
                changeSackButtonRef.classList.add('hidden');
                changeSackButtonRef.classList.remove('flash-button');
            }
        } else {
            console.warn("changeSackButtonRef is null or undefined. Cannot update button visibility.");
        }
    }

    /**
     * Handles changing the sack.
     * Adds current tokens to score and resets current tokens.
     */
    function emptySack() {
        console.log("emptySack called. Current tokens before cashing:", sleigh.currentSackTokens);
        if (sleigh.currentSackTokens > 0) {
            updateGlobalScoreRef(sleigh.currentSackTokens); // Update global score in main2.js
            sleigh.currentSackTokens = 0;
            updateTokenDisplays();
            displayGameStatusRef('Sack Emptied! Harmony Notes added to your score!', 1500); // Changed to notification type
        } else {
            displayGameStatusRef('Sack Empty! No tokens to add to your score yet!', 1500); // Changed to notification type
        }
    }

    /**
     * Updates the game state for Day 1.
     */
    function updateDay1Game(currentTime) { // Pass currentTime here
        console.log(`updateDay1Game: Called. currentTime=${currentTime}, sleigh.x=${sleigh.x}, sleigh.y=${sleigh.y}, obstacles.length=${obstacles.length}, tokens.length=${tokens.length}`);

        // --- Game Timer and Difficulty Progression ---
        if (gameRunning) { // Only update timer if game is active
            const timeSinceLastDifficultyIncrease = currentTime - lastDifficultyIncreaseTime;

            if (timeSinceLastDifficultyIncrease >= DIFFICULTY_INCREASE_INTERVAL) {
                difficultyLevel++;
                lastDifficultyIncreaseTime = currentTime; // Reset timer for next increase

                // Increase difficulty:
                // 1. Increase base scroll speed
                sleigh.targetScrollSpeed = Math.min(sleigh.maxScrollSpeed, sleigh.targetScrollSpeed + 0.2); // Increase by 0.2
                // 2. Make obstacles/tokens spawn more frequently (decrease intervals)
                obstacleSpawnInterval = Math.max(400, obstacleSpawnInterval - 50); // Min 400ms
                tokenSpawnInterval = Math.max(200, tokenSpawnInterval - 30); // Min 200ms

                displayGameStatusRef(`Level Up! Difficulty Level: ${difficultyLevel}`, 1500); // Changed to notification type
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
            sleigh.targetScrollSpeed = Math.min(sleigh.maxScrollSpeed, sleigh.targetScrollSpeed + sleigh.acceleration);
        } else if (keys.ArrowLeft) {
            // Move sleigh left
            sleigh.x = Math.max(sleigh.xBoundsLeft, sleigh.x - sleigh.moveSpeedX);
            // Decrease target scroll speed
            sleigh.targetScrollSpeed = Math.max(sleigh.minScrollSpeed, sleigh.targetScrollSpeed - sleigh.acceleration * 0.5);
        } else {
            // Gradually return to base scroll speed if no horizontal key pressed
            if (sleigh.scrollSpeed > gameConfig.sleighScrollSpeed) {
                sleigh.targetScrollSpeed = Math.max(gameConfig.sleighScrollSpeed, sleigh.targetScrollSpeed - sleigh.acceleration * 0.5);
            } else if (sleigh.scrollSpeed < gameConfig.sleighScrollSpeed) {
                sleigh.targetScrollSpeed = Math.min(gameConfig.sleighScrollSpeed, sleigh.targetScrollSpeed + sleigh.acceleration * 0.5);
            }
        }
        // Smoothly interpolate current scroll speed towards target scroll speed
        sleigh.scrollSpeed += (sleigh.targetScrollSpeed - sleigh.scrollSpeed) * 0.1;


        // --- Move obstacles and tokens ---
        obstacles.forEach(obs => {
            // Defensive checks for malformed objects
            if (typeof obs === 'undefined' || obs === null ||
                typeof obs.initialY === 'undefined' || typeof obs.amplitude === 'undefined' ||
                typeof obs.frequency === 'undefined' || typeof obs.phaseOffset === 'undefined') {
                console.error("Malformed obstacle object detected, skipping:", obs);
                return; // Skip this iteration if the object is malformed
            }
            obs.x -= sleigh.scrollSpeed;
            obs.y = obs.initialY + obs.amplitude * Math.sin(currentTime * obs.frequency + obs.phaseOffset);
        });
        tokens.forEach(tok => {
            // Defensive checks for malformed objects
            if (typeof tok === 'undefined' || tok === null ||
                typeof tok.initialY === 'undefined' || typeof tok.amplitude === 'undefined' ||
                typeof tok.frequency === 'undefined' || typeof tok.phaseOffset === 'undefined') {
                console.error("Malformed token object detected, skipping:", tok);
                return; // Skip this iteration if the object is malformed
            }
            tok.x -= sleigh.scrollSpeed;
            tok.y = tok.initialY + tok.amplitude * Math.sin(currentTime * tok.frequency + tok.phaseOffset);
        });

        // Update scrolling background
        backgroundX -= sleigh.scrollSpeed * 0.5; // Background scrolls slower than foreground elements
        if (backgroundX <= -canvas.width) { // Reset when one full canvas width has scrolled
            backgroundX += canvas.width;
        }

        // Remove off-screen elements
        obstacles = obstacles.filter(obs => !obs.markedForDeletion && obs.x + obs.width > 0);
        tokens = tokens.filter(tok => !tok.markedForDeletion && tok.x + tok.width > 0); // Use tok.width here

        // Spawn new obstacles
        if (currentTime >= lastObstacleSpawnTime) { // Check if enough time has passed since game start + initial delay
            if (currentTime - lastObstacleSpawnTime > obstacleSpawnInterval) {
                const obsWidth = 50 + Math.random() * 50;
                const obsHeight = 50 + Math.random() * 50;
                const obsY = Math.random() * (canvas.height - obsHeight);
                // Randomly choose obstacle type
                const obsType = Math.random() < 0.5 ? 'noise' : 'comfort';
                // Spawn further to the right
                obstacles.push(new Obstacle(canvas.width + INITIAL_SPAWN_OFFSET_X, obsY, obsWidth, obsHeight, obsType));
                lastObstacleSpawnTime = currentTime;
                console.log(`updateDay1Game: Spawned new obstacle. Total obstacles: ${obstacles.length}`);
            }
        }


        // Spawn new tokens
        if (currentTime >= lastTokenSpawnTime) { // Check if enough time has passed since game start + initial delay
            if (currentTime - lastTokenSpawnTime > tokenSpawnInterval) {
                const tokenValue = gameConfig.tokenValues[Math.floor(Math.random() * gameConfig.tokenValues.length)];
                const tokenY = Math.random() * (canvas.height - 30) + 15; // Ensure token is fully visible
                // Spawn further to the right
                tokens.push(new Token(canvas.width + INITIAL_SPAWN_OFFSET_X, tokenY, 30, 30, tokenValue));
                lastTokenSpawnTime = currentTime;
                console.log(`updateDay1Game: Spawned new token. Total tokens: ${tokens.length}`);
            }
        }

        // Collision detection: Sleigh vs. Obstacles
        checkCollisions();

        // Check for game completion (example: after a certain score or time)
        const gameDuration = 60000; // 60 seconds
        if (currentTime - lastFrameTime > gameDuration && gameRunning) { // Use currentTime - lastFrameTime for total elapsed
            gameRunning = false;
            cancelAnimationFrame(animationFrameId);
            removeInputListeners();
            emptySack(); // Empty sack one last time before finishing
            showMessageBoxRef(
                'Day 1 Complete!',
                `Congratulations, Elf! You've completed Santa's Sleigh Service.
                <br><br>Your final score for Day 1: ${getGlobalScoreRef()}
                <br><br><span style="font-size: 28px;">The code to enter today's prize draw is: <b>IMPACT 460T</b></span>`,
                () => {
                    // Advance to next overall game level (Day 2)
                    window.advanceToNextLevel(); // This function is exposed by main2.js
                }, 0, 'modal' // Explicitly set as modal
            );
        }
    }

    /**
     * Handles collision detection between sleigh, obstacles, and tokens.
     */
    function checkCollisions() {
        // Sleigh vs. Obstacles
        for (let i = obstacles.length - 1; i >= 0; i--) {
            const obs = obstacles[i];
            // More accurate AABB collision for rects
            if (sleigh.x < obs.x + obs.width &&
                sleigh.x + sleigh.width > obs.x &&
                sleigh.y < obs.y + obs.height &&
                sleigh.y + sleigh.height > obs.y) {
                // Collision detected!
                console.log("Collision detected with obstacle:", obs.type); // Debugging collision
                console.log(`Sleigh Bounding Box: x=${sleigh.x}, y=${sleigh.y}, width=${sleigh.width}, height=${sleigh.height}`);
                console.log(`Obstacle Bounding Box: x=${obs.x}, y=${obs.y}, width=${obs.width}, height=${obs.height}`);
                window.Day1Game.pauseGame(); // Pause this game using global reference
                // Modified message to include prize draw code
                showMessageBoxRef('CRASH!',
                    `Oh no! You hit a ${obs.type} obstacle. Your quest for Day 1 ends here. Final Score: ${getGlobalScoreRef()}. Level Reached: ${difficultyLevel}.` +
                    `<br><br><span style="font-size: 28px;">The code to enter today's prize draw is: <b>IMPACT 460T</b></span>`,
                    () => {
                        // Instead of calling restartCurrentGameRef directly, give options or return to map
                        // For now, we'll just let the user click OK and stay on the game screen, paused.
                        // They can then use the restart button or back to map button.
                        console.log("Collision message dismissed. Game remains paused.");
                    }, 0, 'modal'); // Explicitly set as modal, no auto-hide
                return; // Stop updating if crashed
            }
        }

        // Sleigh vs. Tokens
        for (let i = tokens.length - 1; i >= 0; i--) {
            const tok = tokens[i];
            // Simple AABB collision for rects (using width/height as tokens are rects)
            if (sleigh.x < tok.x + tok.width &&
                sleigh.x + sleigh.width > tok.x &&
                sleigh.y < tok.y + tok.height &&
                sleigh.y + tok.height > tok.y) {
                if (sleigh.currentSackTokens + tok.value <= sleigh.sackCapacity) {
                    sleigh.currentSackTokens += tok.value;
                    updateTokenDisplays();
                    console.log(`Collected token: ${tok.value}. Current tokens: ${sleigh.currentSackTokens}`); // Debugging token collection
                    tokens[i].markedForDeletion = true; // Mark for deletion
                } else {
                    // Token falls off
                    tokens[i].markedForDeletion = true; // Mark for deletion even if sack is full
                    displayGameStatusRef('Sack Full! A token fell off because your sack is full! Press C to change sack.', 2000); // Use non-modal message
                }
            }
        }
    }


    /**
     * Draws the scrolling background.
     */
    function drawBackground() {
        if (!ctx || !canvas) return; // Ensure context and canvas are available
        // Draw repeating pattern of stars/dots
        ctx.fillStyle = '#004D55'; // Darker petrol for background
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // Draw repeating pattern (simple stars or dots)
        ctx.fillStyle = '#004D55'; // Slightly lighter petrol for stars (using a consistent color from COLORS)
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
        if (!ctx) return;
        if (sleigh.image && sleigh.image.complete) {
            ctx.drawImage(sleigh.image, sleigh.x, sleigh.y, sleigh.width, sleigh.height);
            // console.log(`drawSleigh: Drawing sleigh at x=${sleigh.x}, y=${sleigh.y}`);
        } else {
            console.warn("drawSleigh: Sleigh image not loaded or complete, drawing fallback rectangle.");
            if (ctx) {
                ctx.fillStyle = '#FFFFFF';
                ctx.fillRect(sleigh.x, sleigh.y, sleigh.width, sleigh.height);
                ctx.strokeStyle = '#B22222';
                ctx.lineWidth = 3;
                ctx.strokeRect(sleigh.x, sleigh.y, sleigh.width, sleigh.height);
                ctx.beginPath();
                ctx.moveTo(sleigh.x + sleigh.width, sleigh.y + sleigh.height / 2);
                ctx.lineTo(sleigh.x + sleigh.width + 15, sleigh.y + sleigh.height / 2 - 15);
                ctx.lineTo(sleigh.x + sleigh.width + 15, sleigh.y + sleigh.height / 2 + 15);
                ctx.closePath();
                ctx.fillStyle = '#A7D9D3';
                ctx.fill();
            }
        }
    }

    /**
     * Draws the sack fullness indicator above the sleigh.
     */
    function drawSackIndicator() {
        if (!ctx) return;
        // console.log(`drawSackIndicator: Drawing sack indicator at x=${sleigh.x}, y=${sleigh.y - 18}`);
        const barWidth = sleigh.width * 0.8;
        const barHeight = 8;
        const barX = sleigh.x + (sleigh.width - barWidth) / 2;
        const barY = sleigh.y - barHeight - 10; // 10 pixels above sleigh

        // Draw background of the bar
        ctx.fillStyle = '#333333'; // Dark grey background
        ctx.fillRect(barX, barY, barWidth, barHeight);
        ctx.strokeStyle = '#FFFFFF'; // White border
        ctx.lineWidth = 1;
        ctx.strokeRect(barX, barY, barWidth, barHeight);

        // Calculate fill percentage
        const fillPercentage = sleigh.currentSackTokens / sleigh.sackCapacity;
        const filledWidth = barWidth * fillPercentage;

        // Determine fill color based on fullness
        let fillColor;
        if (fillPercentage < 0.5) {
            fillColor = '#A7D9D3'; // Greenish (EPOS Teal)
        } else if (fillPercentage < 0.8) {
            fillColor = '#FFD700'; // Yellowish
        } else {
            fillColor = '#B22222'; // Reddish (EPOS Coral)
        }

        // Draw the filled portion
        ctx.fillStyle = fillColor;
        ctx.fillRect(barX, barY, filledWidth, barHeight);
    }

    /**
     * Draws the current difficulty level on the canvas.
     */
    function drawDifficultyLevel() {
        if (!ctx || !canvas) return;
        ctx.fillStyle = '#FFFFFF'; // White text
        ctx.font = 'bold 20px Inter';
        ctx.textAlign = 'right';
        ctx.textBaseline = 'top';
        ctx.fillText(`Level: ${difficultyLevel}`, canvas.width - 20, 20); // Top right corner
    }

    /**
     * Draws all game elements for Day 1.
     */
    function drawDay1Game(currentTime) { // Pass currentTime to draw for oscillating objects
        if (!ctx || !canvas) {
            console.warn("drawDay1Game: Context or canvas not available.");
            return;
        }
        ctx.clearRect(0, 0, canvas.width, canvas.height); // Clear canvas
        drawBackground(); // Draw scrolling background first
        drawSleigh();     // Draw the enhanced sleigh
        drawSackIndicator(); // Draw the sack fullness indicator
        console.log(`drawDay1Game: Drawing ${obstacles.length} obstacles and ${tokens.length} tokens.`);
        obstacles.forEach(obs => obs.draw(ctx)); // Pass ctx to draw method
        tokens.forEach(tok => tok.draw(ctx)); // Pass ctx to draw method
        drawDifficultyLevel(); // Draw the current difficulty level
    }

    /**
     * Main game loop for Day 1.
     * @param {DOMHighResTimeStamp} currentTime The current time provided by requestAnimationFrame.
     */
    function gameLoop(currentTime) {
        console.log("gameLoop: Top of function. gameRunning state:", gameRunning); // Added this log
        if (!gameRunning) {
            console.log("gameLoop: Game is not running, returning.");
            return;
        }
        console.log(`gameLoop: Running. currentTime=${currentTime}, obstacles.length=${obstacles.length}, tokens.length=${tokens.length}`);

        // Calculate deltaTime for frame-rate independent movement
        const deltaTime = currentTime - lastFrameTime;
        lastFrameTime = currentTime;

        updateDay1Game(currentTime); // Pass currentTime to update
        drawDay1Game(currentTime); // Pass currentTime to draw

        animationFrameId = requestAnimationFrame(gameLoop);
    }

    // --- Input Handling ---

    function setupInputListeners() {
        document.addEventListener('keydown', handleKeyDown);
        document.addEventListener('keyup', handleKeyUp);
        if (changeSackButtonRef) {
            changeSackButtonRef.addEventListener('click', emptySack);
        }
        console.log("Day 1 input listeners added.");
        console.trace("Call stack for setupInputListeners:"); // Add trace here
    }

    function removeInputListeners() {
        document.removeEventListener('keydown', handleKeyDown);
        document.removeEventListener('keyup', handleKeyUp);
        if (changeSackButtonRef) {
            changeSackButtonRef.removeEventListener('click', emptySack);
        }
        console.log("Day 1 input listeners removed.");
        console.trace("Call stack for removeInputListeners:"); // Add trace here
    }

    function handleKeyDown(e) {
        if (!gameRunning) return;
        // Set key state to true
        if (e.key in keys) {
            keys[e.key] = true;
        }

        // Handle 'c' key for emptying sack (only on initial press)
        if ((e.key === 'c' || e.key === 'C') && !cKeyHandled) {
            emptySack();
            cKeyHandled = true; // Set flag to prevent repeated calls while key is held down
        }
    }

    function handleKeyUp(e) {
        // Set key state to false
        if (e.key in keys) {
            keys[e.key] = false;
        }
        // Reset cKeyHandled when 'c' key is released
        if (e.key === 'c' || e.key === 'C') {
            cKeyHandled = false;
        }
    }

    // --- Exported Functions for main2.js (exposed globally via window.Day1Game) ---

    /**
     * Initializes the Day 1 game.
     * @param {HTMLCanvasElement} canvasElement - The canvas DOM element.
     * @param {CanvasRenderingContext2D} context - The 2D rendering context.
     * @param {function} messageBoxFunction - Reference to main2.js's showMessageBox.
     * @param {function} displayGameStatusFunction - Reference to main2.js's displayGameStatus.
     * @param {function} updateScoreFunction - Function to update the global score in main2.js.
     * @param {function} restartGameFunction - Function to restart the current game in main2.js.
     * @param {function} getGlobalScoreFunction - Function to get the current global score from main2.js.
     * @param {HTMLElement} currentTokensDisplayParam - Reference to the current tokens UI element.
     * @param {HTMLElement} sackCapacityDisplayParam - Reference to the sack capacity UI element.
     * @param {HTMLElement} changeSackButtonParam - Reference to the change sack button UI element.
     * @param {HTMLElement} startButtonRefParam - Reference to the global start button.
     * @param {HTMLElement} pauseButtonRefParam - Reference to the global pause button.
     * @param {HTMLElement} restartButtonRefParam - Reference to the global restart button.
     * @param {object} playerConfig - Player details including superpower.
     * @param {number} levelInfo - Current game level.
     */
    window.Day1Game.initDay1Game = async function(canvasElement, context, messageBoxFunction, displayGameStatusFunction, updateScoreFunction, restartGameFunction, getGlobalScoreFunction, currentTokensDisplayParam, sackCapacityDisplayParam, changeSackButtonParam, startButtonRefParam, pauseButtonRefParam, restartButtonRefParam, playerConfig, levelInfo) {
        canvas = canvasElement;
        ctx = context;
        showMessageBoxRef = messageBoxFunction;
        displayGameStatusRef = displayGameStatusFunction; // Correctly assigned
        updateGlobalScoreRef = updateScoreFunction;
        restartCurrentGameRef = restartGameFunction;
        getGlobalScoreRef = getGlobalScoreFunction; // Correctly assigned

        // Store UI element references
        currentTokensDisplayRef = currentTokensDisplayParam;
        sackCapacityDisplayRef = sackCapacityDisplayParam;
        changeSackButtonRef = changeSackButtonParam;
        startButtonRef = startButtonRefParam;
        pauseButtonRef = pauseButtonRefParam;
        restartButtonRef = restartButtonRefParam; // Corrected typo here

        // --- IMPORTANT: Wait for canvas to have dimensions ---
        // This loop ensures the canvas has been laid out by the browser
        // before we proceed with calculations that depend on its size.
        let checkCanvasDimensions = () => {
            return new Promise(resolve => {
                const check = () => {
                    // Re-read dimensions from the element itself, as they might have been set by CSS/parent
                    canvas.width = canvas.parentElement.clientWidth;
                    canvas.height = canvas.parentElement.clientHeight;

                    if (canvas.width > 0 && canvas.height > 0) {
                        console.log("Day1Game init: Canvas dimensions are now:", canvas.width, canvas.height);
                        resolve();
                    } else {
                        console.log("Day1Game init: Canvas dimensions are still 0, retrying...");
                        requestAnimationFrame(check); // Continue checking on the next animation frame
                    }
                };
                requestAnimationFrame(check); // Start the check loop
            });
        };
        await checkCanvasDimensions(); // Wait for the dimensions to be set
        // --- End of waiting for canvas dimensions ---


        // --- Debugging Canvas Dimensions (now should be non-zero) ---
        console.log("Day1Game init: Canvas dimensions after wait:", canvas.width, canvas.height);
        // --- End Debugging ---


        day1Player = playerConfig; // Store the player object
        day1Level = levelInfo;     // Store the current level

        // Initialize obstacles and tokens arrays *before* asset loading,
        // so they are always defined, even if loading fails.
        obstacles = [];
        tokens = [];

        // Show loading message while assets load
        drawLoadingScreen(); // Call loading screen after canvas has dimensions

        try {
            await loadAssets(); // Wait for all images to load
            sleigh.image = assets.sleigh; // Assign the loaded sleigh image
            console.log("initDay1Game: Assets loaded successfully, calling message box."); // New log
            displayGameStatusRef('Assets Loaded! Ready for launch!', 1000); // Changed to notification type
        } catch (error) {
            showMessageBoxRef('Error Loading Assets', 'Could not load game images. Please refresh. Check console for details.', null, 5000, 'modal'); // Keep as modal for critical error
            console.error("Asset loading error:", error);
            return; // Prevent game from starting if assets fail
        }

        // Apply player superpower configuration
        if (day1Player.superpower && ['IMPACT', 'ADAPT', 'EXPAND'].includes(day1Player.superpower)) {
            gameConfig = {
                IMPACT: {
                    sleighScrollSpeed: 4.5, // Base scroll speed
                    sackCapacity: 50,
                    tokenValues: [10, 20, 30]
                },
                ADAPT: {
                    sleighScrollSpeed: 5.0, // Increased scroll speed for ADAPT (makes it faster/harder but potentially higher score)
                    sackCapacity: 60,       // Increased sack capacity for ADAPT (more tokens per run)
                    tokenValues: [10, 20, 30]
                },
                EXPAND: {
                    sleighScrollSpeed: 3.0,
                    sackCapacity: 70,
                    tokenValues: [10, 20, 30]
                }
            }[day1Player.superpower];
        } else {
            console.warn("Invalid or no superpower provided. Using default game config.");
            // gameConfig is already defaulted at the top, so no action needed here.
        }

        sleigh.scrollSpeed = gameConfig.sleighScrollSpeed; // Set initial scroll speed
        sleigh.targetScrollSpeed = gameConfig.sleighScrollSpeed; // Set target scroll speed
        sleigh.minScrollSpeed = gameConfig.sleighScrollSpeed * 0.7; // Define min/max relative to base
        sleigh.maxScrollSpeed = gameConfig.sleighScrollSpeed * 1.3;
        sleigh.sackCapacity = gameConfig.sackCapacity;
        sleigh.currentSackTokens = 0; // Reset tokens for new game

        // Reset sleigh position and speed for a clean restart
        sleigh.x = 100; // Initial X position
        // Corrected initial Y position to be centered vertically based on canvas height
        sleigh.y = (canvas.height / 2) - (sleigh.height / 2);
        sleigh.speedY = 0; // Crucial: Reset vertical speed on init

        // Also reset key states to ensure no lingering 'pressed' state
        keys.ArrowUp = false;
        keys.ArrowDown = false;
        keys.ArrowLeft = false;
        keys.ArrowRight = false;
        keys.c = false; // Corrected to 'c'
        cKeyHandled = false; // Reset the flag

        // Reset difficulty and timer on init
        difficultyLevel = 1;
        lastDifficultyIncreaseTime = 0; // Will be set on game start
        lastFrameTime = 0; // Reset lastFrameTime

        // Spawn intervals are now initialized in startGame callback
        obstacleSpawnInterval = 1500;
        tokenSpawnInterval = 800;
        lastTokenSpawnTime = 0;
        lastObstacleSpawnTime = 0;


        // The changeSackButton is now primarily a visual cue, the action is on 'c' key
        if (changeSackButtonRef) {
            changeSackButtonRef.classList.remove('hidden'); // Ensure it's visible for Day 1
            changeSackButtonRef.textContent = 'Change Sack (Press C)'; // Update button text
        }

        updateTokenDisplays(); // Update new displays
        drawDay1Game(performance.now()); // Draw initial game state after assets load
    };

    /**
     * Starts the Day 1 game loop.
     */
     window.Day1Game.startGame = function() {
        if (!gameRunning) { // Only start if game is not already running
            gameRunning = true;
            setupInputListeners(); // Add input listeners when game starts

            // Show "How To Play" message when the game starts
            showMessageBoxRef(
                'How To Play',
                `Welcome to the EPOS Holiday Harmony Quest!
                <br><br>
                Your mission is to collect Harmony Notes and avoid obstacles using your sleigh.
                <br><br>
                Use the <b>Arrow Up</b> and <b>Arrow Down</b> keys to move your sleigh vertically.
                <br>
                Use the <b>Arrow Left</b> and <b>Arrow Right</b> keys to adjust your horizontal speed.
                <br>
                Press <b>'C'</b> to empty your sack and add collected tokens to your score.<br><br>Good luck, and happy questing!`,
                () => { // This callback will be executed when the user clicks OK
                    console.log("--- MESSAGE BOX OK CLICKED! Attempting to start game loop. ---"); // VERY IMPORTANT NEW LOG
                    // Only start the game loop after the message box is dismissed
                    lastFrameTime = performance.now(); // Initialize lastFrameTime here
                    lastDifficultyIncreaseTime = performance.now(); // Start difficulty timer here
                    // Add an initial delay to prevent immediate collisions
                    lastObstacleSpawnTime = performance.now() + INITIAL_SPAWN_DELAY;
                    lastTokenSpawnTime = performance.now() + INITIAL_SPAWN_DELAY;
                    animationFrameId = requestAnimationFrame(gameLoop);
                    console.log("showMessageBoxRef callback: requestAnimationFrame for gameLoop initiated.");
                },
                0, 'modal' // Explicitly set as modal, no auto-hide
            );

            // Update global control button visibility
            if (startButtonRef) startButtonRef.classList.add('hidden');
            if (pauseButtonRef) pauseButtonRef.classList.remove('hidden');
            if (restartButtonRef) restartButtonRef.classList.remove('hidden');
        }
    };

    /**
     * Pauses the Day 1 game.
     */
    window.Day1Game.pauseGame = function() { // Renamed to pauseGame for consistency with main2.js
        if (gameRunning) {
            gameRunning = false;
            cancelAnimationFrame(animationFrameId);
            removeInputListeners(); // Remove input listeners when game pauses

            // Update global control button visibility
            if (startButtonRef) startButtonRef.classList.remove('hidden');
            if (pauseButtonRef) pauseButtonRef.classList.add('hidden');
        }
    };

    /**
     * Restarts the Day 1 game. This function is called from main2.js.
     * It's essentially a wrapper to trigger a full re-initialization of Day 1.
     */
    window.Day1Game.restartGame = function() { // Renamed to restartGame for consistency with main2.js
        window.Day1Game.pauseGame(); // Pause current game
        showMessageBoxRef('Restarting Day 1', 'Starting the sleigh service challenge from the beginning.', () => {
            // Reset internal state and then start the game.
            resetDay1GameInternal();
            window.Day1Game.startGame();
        }, null, 2000, 'modal'); // Explicitly set as modal
    };

    /**
     * Resets the internal state of the Day 1 game without re-initializing assets.
     * This is useful when restarting a day.
     */
    function resetDay1GameInternal() { // Renamed for clarity
        // Reset internal game state variables for Day 1
        sleigh.x = 100;
        sleigh.y = (canvas.height / 2) - (sleigh.height / 2); // Recalculate based on current canvas height
        sleigh.speedY = 0;
        sleigh.currentSackTokens = 0;
        obstacles = [];
        tokens = [];
        backgroundX = 0; // **Use the global backgroundX**
        difficultyLevel = 1;
        lastDifficultyIncreaseTime = 0; // Will be set on game start
        lastFrameTime = 0; // Reset lastFrameTime
        obstacleSpawnInterval = 1500;
        tokenSpawnInterval = 800;
        // Spawn intervals are now initialized in startGame callback
        lastObstacleSpawnTime = 0;
        lastTokenSpawnTime = 0;
        cKeyHandled = false; // Reset the flag

        // Re-apply game config based on player's superpower
        if (day1Player.superpower && ['IMPACT', 'ADAPT', 'EXPAND'].includes(day1Player.superpower)) {
            gameConfig = {
                IMPACT: {
                    sleighScrollSpeed: 4.5,
                    sackCapacity: 50,
                    tokenValues: [10, 20, 30]
                },
                ADAPT: {
                    sleighScrollSpeed: 5.0, // Increased scroll speed for ADAPT
                    sackCapacity: 60,       // Increased sack capacity for ADAPT
                    tokenValues: [10, 20, 30]
                },
                EXPAND: {
                    sleighScrollSpeed: 3.0,
                    sackCapacity: 70,
                    tokenValues: [10, 20, 30]
                }
            }[day1Player.superpower];
        } else {
            console.warn("Invalid or no superpower provided during reset. Using default game config.");
            // gameConfig is already defaulted at the top, so no action needed here.
        }

        sleigh.scrollSpeed = gameConfig.sleighScrollSpeed;
        sleigh.targetScrollSpeed = gameConfig.sleighScrollSpeed;
        sleigh.minScrollSpeed = gameConfig.sleighScrollSpeed * 0.7;
        sleigh.maxScrollSpeed = gameConfig.sleighScrollSpeed * 1.3;
        sleigh.sackCapacity = gameConfig.sackCapacity;

        updateTokenDisplays();
        // Do NOT remove input listeners here; they should only be removed on pause/game over.
        // Clear canvas
        if (ctx && canvas) {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
        }
    };

    // Expose the internal reset function to the global Day1Game object
    window.Day1Game.resetDay1Game = resetDay1GameInternal;

    // Initial console log to confirm script loaded
    console.log("Day1.js script loaded.");

})(); // End of IIFE
