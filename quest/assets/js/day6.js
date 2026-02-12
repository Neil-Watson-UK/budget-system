/**
 * day6.js
 *
 * Contains the specific game logic for Day 6: Santa's Bubble Pop Challenge.
 * Objective: Pop groups of 3+ matching present bubbles.
 *
 * @version 6.0.7
 * @date 2025-07-25
 * @author Gemini Assistant
 */

// Define a global object to hold Day6Game functions immediately
window.Day6Game = window.Day6Game || {};
console.log("window.Day6Game namespace defined and accessible for Santa's Bubble Pop Challenge.");

(function() { // Wrap the entire script in an IIFE

    // --- Matter.js Aliases ---
    const Engine = Matter.Engine,
        Render = Matter.Render,
        Runner = Matter.Runner,
        Bodies = Matter.Bodies,
        Composite = Matter.Composite,
        Events = Matter.Events,
        Query = Matter.Query,
        Vector = Matter.Vector;

    // --- Tone.js Audio Setup ---
    let launchSynth;
    let popSynth; // For popping bubbles
    let fallSynth; // For bubbles falling
    let backgroundMusicPart;
    let backgroundMusicSynth;

    // --- Game Configuration ---
    const BUBBLE_RADIUS = 20;
    const BUBBLE_COLORS = ['#FF6347', '#FFD700', '#6A5ACD', '#3CB371', '#4682B4'];
    const BUBBLE_TEXTURES = {
        '#FF6347': '🎁', // Red present
        '#FFD700': '🔔', // Gold bell
        '#6A5ACD': '🍬', // Purple candy
        '#3CB371': '🌲', // Green tree
        '#4682B4': '❄️'  // Blue snowflake
    };
    const MAX_BUBBLES = 50;
    const LAUNCH_FORCE = 0.05;
    const ANGLE_VARIANCE = Math.PI / 16;
    const GAME_TIME = 90; // seconds

    // --- Game State ---
    let engine;
    let render;
    let runner;
    let canvas;
    let ctx;
    let mouseConstraint;
    let cannon;
    let bubblesInWorld = []; // A structured list of bubbles in the world
    let score = 0;
    let timer = GAME_TIME;
    let gameRunning = false;
    let lastFrameTime = 0;
    let showMessageBoxRef;
    let updateGlobalScoreRef;
    let restartCurrentGameRef;
    let advanceToNextLevelRef;
    let playerSuperpower;

    /**
     * Initializes Tone.js audio instruments.
     */
    async function setupAudio() {
        try {
            if (Tone.context.state !== 'running') {
                await Tone.start();
            }
            backgroundMusicSynth = new Tone.Synth().toDestination();
            backgroundMusicPart = new Tone.Part((time) => {
                backgroundMusicSynth.triggerAttackRelease("C4", "8n", time);
            }, [[0, 0]]);
            backgroundMusicPart.loop = true;
            backgroundMusicPart.loopEnd = "1m";

            launchSynth = new Tone.MembraneSynth().toDestination();
            popSynth = new Tone.PolySynth(Tone.Synth).toDestination();
            fallSynth = new Tone.FMSynth().toDestination();
        } catch (e) {
            console.error("Tone.js audio setup failed:", e);
        }
    }

    /**
     * Initializes the Matter.js engine and renderer.
     */
    function setupPhysics() {
        engine = Engine.create({ gravity: { y: 1 } });
        render = Render.create({
            canvas: canvas,
            engine: engine,
            options: {
                width: canvas.width,
                height: canvas.height,
                background: '#3A5E65',
                wireframes: false
            }
        });

        Render.run(render);
        runner = Runner.create();
        Runner.run(runner, engine);

        // Add walls and floor
        const walls = [
            Bodies.rectangle(canvas.width / 2, -25, canvas.width, 50, { isStatic: true, render: { fillStyle: '#00353D' } }), // Top wall
            Bodies.rectangle(canvas.width / 2, canvas.height + 25, canvas.width, 50, { isStatic: true, render: { fillStyle: '#00353D' } }), // Bottom wall
            Bodies.rectangle(-25, canvas.height / 2, 50, canvas.height, { isStatic: true, render: { fillStyle: '#00353D' } }), // Left wall
            Bodies.rectangle(canvas.width + 25, canvas.height / 2, 50, canvas.height, { isStatic: true, render: { fillStyle: '#00353D' } }) // Right wall
        ];
        Composite.add(engine.world, walls);
    }

    /**
     * Creates and adds a bubble to the world.
     * @param {number} x The x position.
     * @param {number} y The y position.
     * @param {string} color The color of the bubble.
     */
    function createBubble(x, y, color) {
        const bubble = Bodies.circle(x, y, BUBBLE_RADIUS, {
            frictionAir: 0.05,
            restitution: 0.8,
            render: {
                fillStyle: color,
                strokeStyle: '#fff',
                lineWidth: 2,
                sprite: {
                    texture: `data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='40' height='40'><text x='50%' y='50%' dominant-baseline='middle' text-anchor='middle' font-size='24' fill='%23ffffff'>${BUBBLE_TEXTURES[color]}</text></svg>`,
                    xScale: 1,
                    yScale: 1
                }
            }
        });
        bubblesInWorld.push(bubble);
        Composite.add(engine.world, bubble);
        return bubble;
    }

    /**
     * Creates the cannon/launcher.
     */
    function createCannon() {
        cannon = Bodies.circle(canvas.width / 2, canvas.height - 50, 20, {
            isStatic: true,
            render: { fillStyle: '#ff5549' } // EPOS Coral
        });
        Composite.add(engine.world, cannon);
    }

    /**
     * Checks for and pops groups of 3 or more matching bubbles.
     */
    function popMatchingBubbles() {
        const bubblesToPop = [];
        const checkedBubbles = new Set();

        function findMatchingBubbles(startBubble) {
            const matches = new Set();
            const queue = [startBubble];
            matches.add(startBubble);
            checkedBubbles.add(startBubble);
            const color = startBubble.render.fillStyle;

            while (queue.length > 0) {
                const current = queue.shift();
                const nearby = Query.within(bubblesInWorld, current.position.x, current.position.y, 4 * BUBBLE_RADIUS);

                nearby.forEach(body => {
                    if (body !== current && !checkedBubbles.has(body) && body.render.fillStyle === color) {
                        matches.add(body);
                        checkedBubbles.add(body);
                        queue.push(body);
                    }
                });
            }
            return Array.from(matches);
        }

        bubblesInWorld.forEach(bubble => {
            if (!checkedBubbles.has(bubble)) {
                const group = findMatchingBubbles(bubble);
                if (group.length >= 3) {
                    bubblesToPop.push(...group);
                }
            }
        });

        if (bubblesToPop.length > 0) {
            bubblesToPop.forEach(bubble => {
                Composite.remove(engine.world, bubble);
                const index = bubblesInWorld.indexOf(bubble);
                if (index > -1) {
                    bubblesInWorld.splice(index, 1);
                }
            });
            // Score for popping a group
            const points = bubblesToPop.length * 100;
            updateGlobalScoreRef(points);
            popSynth.triggerAttackRelease(["C5", "E5", "G5"], "8n");
            showMessageBoxRef('Bubble Pop!', `+${points} points!`, null, 1000);
        }
    }

    /**
     * Main game loop.
     */
    function gameLoop(timestamp) {
        if (!gameRunning) return;
        const delta = (timestamp - lastFrameTime) / 1000;
        lastFrameTime = timestamp;

        timer -= delta;
        document.getElementById('levelDisplay').textContent = `Time: ${Math.max(0, Math.floor(timer))}s`;

        if (timer <= 0) {
            endGame();
            return;
        }

        // Check for groups to pop after each physics step
        popMatchingBubbles();

        animationFrameId = requestAnimationFrame(gameLoop);
    }

    /**
     * Ends the game and shows a win/loss message.
     */
    function endGame() {
        gameRunning = false;
        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
        }
        removeInputListeners();
        if (backgroundMusicPart) {
            backgroundMusicPart.stop();
        }

        let title, message;
        if (score >= 5000) {
            title = 'Challenge Complete!';
            message = 'You popped enough bubbles to restore harmony! Day 6 complete!';
            advanceToNextLevelRef();
        } else {
            title = 'Challenge Failed';
            message = 'You didn\'t pop enough bubbles. Try again to save the harmony!';
            restartCurrentGameRef();
        }

        showMessageBoxRef(title, message, null, 0, true);
    }

    /**
     * Handles mouse clicks to launch bubbles.
     * @param {MouseEvent} event The mouse event.
     */
    function handleMouseClick(event) {
        if (!gameRunning) return;

        const rect = canvas.getBoundingClientRect();
        const mouseX = event.clientX - rect.left;
        const mouseY = event.clientY - rect.top;

        // Launch a bubble from the cannon towards the mouse position
        const randomColor = BUBBLE_COLORS[Math.floor(Math.random() * BUBBLE_COLORS.length)];
        const newBubble = createBubble(cannon.position.x, cannon.position.y, randomColor);

        const direction = Vector.sub({ x: mouseX, y: mouseY }, cannon.position);
        const force = Vector.mult(Vector.normalise(direction), LAUNCH_FORCE);
        Matter.Body.applyForce(newBubble, cannon.position, force);

        launchSynth.triggerAttackRelease("C2", "8n");
    }

    /**
     * Adds event listeners for game input.
     */
    function addInputListeners() {
        canvas.addEventListener('click', handleMouseClick);
    }

    /**
     * Removes event listeners for game input.
     */
    function removeInputListeners() {
        canvas.removeEventListener('click', handleMouseClick);
    }

    // --- Exposed Functions for main2.js ---

    /**
     * Initializes the Day 6 game module.
     */
    window.Day6Game.init = function(canvasRef, showMessageBox, displayGameStatus, updateGlobalScore, getGlobalScore, restartGame, advanceToNextLevel, superpower) {
        canvas = canvasRef;
        showMessageBoxRef = showMessageBox;
        updateGlobalScoreRef = updateGlobalScore;
        restartCurrentGameRef = restartGame;
        advanceToNextLevelRef = advanceToNextLevel;
        playerSuperpower = superpower;
    };

    /**
     * Starts the Day 6 game.
     */
    window.Day6Game.startGame = function() {
        score = 0;
        timer = GAME_TIME;
        bubblesInWorld = [];
        Composite.clear(engine.world);
        setupPhysics();
        createCannon();
        addInputListeners();
        gameRunning = true;
        lastFrameTime = performance.now();
        gameLoop();
        setupAudio();

        showMessageBoxRef('Day 6: Santa\'s Bubble Pop Challenge', 'Click to launch bubbles. Pop groups of 3 or more matching ones to score!', null, 0, true);
        console.log("Day 6 Santa's Bubble Pop Challenge started.");
    };

    /**
     * Pauses the Day 6 game.
     */
    window.Day6Game.pauseGame = function() {
        gameRunning = false;
        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
        }
        removeInputListeners();
        if (backgroundMusicPart) {
            backgroundMusicPart.stop();
        }

        showMessageBoxRef('Game Paused', 'The Bubble Pop Challenge is on hold. Click OK to resume.', () => {
            lastFrameTime = performance.now();
            gameRunning = true;
            addInputListeners();
            if (backgroundMusicPart) {
                backgroundMusicPart.start();
            }
            gameLoop();
        }, 0, true);
        console.log("Day 6 Santa's Bubble Pop Challenge Paused.");
    };

    /**
     * Resets the Day 6 game to its initial state.
     */
    window.Day6Game.resetGame = function() {
        gameRunning = false;
        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
        }
        removeInputListeners();
        if (backgroundMusicPart) {
            backgroundMusicPart.stop();
        }
        // Clear all bodies from the world
        Composite.clear(engine.world);
        bubblesInWorld.length = 0;
        console.log("Day 6 Santa's Bubble Pop Challenge Restart requested.");
    };

    /**
     * Handles window resize events to adjust canvas dimensions and redraw the game.
     */
    window.Day6Game.onWindowResize = function() {
        if (canvas && canvas.parentElement && render) {
            canvas.width = canvas.parentElement.clientWidth;
            canvas.height = canvas.parentElement.clientHeight;
            
            // Correct way to resize the Matter.js renderer
            render.options.width = canvas.width;
            render.options.height = canvas.height;
            Render.setPixelRatio(render, window.devicePixelRatio); // Ensure pixel ratio is correct

            // Reset camera bounds to the default view (fixed view for this game)
            render.bounds.min.x = 0;
            render.bounds.min.y = 0;
            render.bounds.max.x = canvas.width;
            render.bounds.max.y = canvas.height;

            // Update mouse constraint offset after camera reset
            if (mouseConstraint && mouseConstraint.mouse) {
                 Matter.Mouse.setOffset(mouseConstraint.mouse, render.bounds.min);
            }

            // Re-position cannon
            if (cannon) {
                Matter.Body.setPosition(cannon, { x: canvas.width / 2, y: canvas.height - 50 });
            }
        }
    };

    console.log("Day6.js script loaded.");
})();
