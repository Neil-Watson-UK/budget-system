/**
 * day4.js
 *
 * Contains the specific game logic for Day 4: The Signal Routing Puzzle.
 * This module is designed to be imported and controlled by main2.js.
 *
 * @version 1.0.19
 * @date 2025-07-22
 * @author Gemini Assistant
 */

// Define a global object to hold Day4Game functions immediately
window.Day4Game = window.Day4Game || {};
console.log("window.Day4Game namespace defined and accessible.");

// Wrap the internal game state and logic in an IIFE to prevent global variable conflicts
(function() {

    // --- Tone.js Audio Setup ---
    let rotateSound; // For pipe rotation clicks
    let connectSound;
    let winSound;
    let loseSound;
    let backgroundMusic; // The Tone.Loop for scheduling background notes
    let bgMusicSynth;    // Tone.Synth specifically for background music notes
    let backgroundMusicGain; // Tone.Gain node for background music volume control

    // --- Internal Game State for Day 4 (Signal Routing Puzzle) ---
    // These variables are now scoped within this IIFE
    let canvas;
    let ctx;
    let showModalMessageBoxRef; // Reference to the main2.js showMessageBox (modal)
    let displayGameStatusRef;   // Reference to the main2.js displayGameStatus (non-modal)
    let updateGlobalScoreRef; // Reference to the main2.js updateGlobalScore.
    let restartCurrentGameRef; // Reference to function in main2.js to restart current day's game
    let getGlobalScoreRef; // Reference to function to get the current global score from main2.js

    let gameRunning = false;
    let animationFrameId;
    let lastFrameTime = 0;

    let day4Player = {
        superpower: null,
        level: 4 // This module is specifically for Day 4
    };

    // Grid and puzzle state
    const NUM_GRID_CELLS = 6; // Number of cells for the grid (e.g., 6x6)
    let CELL_SIZE; // Will be calculated dynamically based on canvas size
    const UI_SPACE_HEIGHT = 100; // Space for UI below the grid

    let grid = []; // 2D array representing the puzzle grid
    let inputs = []; // Array of {row, col, type, targetType}
    let outputs = []; // Array of {row, col, type}
    let connections = []; // Array of currently active connections

    // Pipe types and their rotations (0: N, 1: E, 2: S, 3: W)
    // Each array element represents the open sides of the pipe for that rotation
    const PIPE_TYPES = {
        EMPTY: [],
        STRAIGHT: [[0, 2], [1, 3]], // N-S, E-W
        CORNER: [[0, 1], [1, 2], [2, 3], [3, 0]], // N-E, E-S, S-W, W-N
        T_JUNCTION: [[0, 1, 2], [1, 2, 3], [2, 3, 0], [3, 0, 1]], // N-E-S, E-S-W, S-W-N, W-N-E
        CROSS: [[0, 1, 2, 3]] // All directions
    };

    // Colors for drawing
    const COLORS = {
        background: '#002B32', // Dark Petrol
        gridLine: '#004D55', // Lighter Petrol for grid lines
        pipeUnconnected: '#A7D9D3', // EPOS Mint for unconnected pipes
        pipeConnected: '#FFD700', // Gold for connected pipes
        inputNode: '#B22222', // Red for input
        outputNode: '#4CAF50', // Green for output
        text: '#FFFFFF',
        timerFill: '#A7D9D3',
        timerBackground: '#004D55'
    };

    let gameScore = 0;
    let timeRemaining = 60; // 60 seconds to complete the puzzle
    let totalMoves = 0; // Track player moves

    // Debugging click visualization
    let debugClickPoint = null; // {x, y, endTime}

    // --- Game Configuration for Day 4 (can be adjusted by superpower) ---
    let gameConfig = {
        IMPACT: {
            timeLimit: 75, // More time
            initialBrokenNodes: 0, // Fewer broken nodes
            scoreMultiplier: 1.2 // Higher score
        },
        ADAPT: {
            timeLimit: 60,
            initialBrokenNodes: 1, // Default
            scoreMultiplier: 1.0,
            canUndo: true // Special ability
        },
        EXPAND: {
            timeLimit: 60,
            initialBrokenNodes: 0, // Fewer broken nodes
            scoreMultiplier: 1.1,
            bonusTimePerConnection: 5 // Get bonus time
        }
    };

    // --- Game Object Classes ---
    class PipeSegment {
        constructor(row, col, type, rotation = 0, isBroken = false) {
            this.row = row;
            this.col = col;
            this.type = type; // e.g., 'STRAIGHT', 'CORNER'
            this.rotation = rotation; // 0, 1, 2, 3 (N, E, S, W orientation for open sides)
            this.isBroken = isBroken; // Cannot be rotated if broken
            this.isConnected = false; // Visual state
        }

        getOpenSides() {
            if (this.isBroken) return [];
            const pipeTypeDefinition = PIPE_TYPES[this.type];
            if (!Array.isArray(pipeTypeDefinition)) {
                console.error(`ERROR: PIPE_TYPES['${this.type}'] is not an array. Value:`, pipeTypeDefinition, `at (${this.row}, ${this.col})`);
                return []; // Defensive return
            }
            if (pipeTypeDefinition.length === 0) {
                console.error(`ERROR: PIPE_TYPES['${this.type}'] is an empty array. Value:`, pipeTypeDefinition, `at (${this.row}, ${this.col})`);
                return []; // Defensive return
            }

            const baseSides = pipeTypeDefinition[0];
            return baseSides.map(side => (side + this.rotation) % 4); // Apply rotation
        }

        rotate() {
            if (this.isBroken) return;
            this.rotation = (this.rotation + 1) % PIPE_TYPES[this.type].length;
            totalMoves++;
            if (rotateSound) rotateSound.triggerAttackRelease("C4", "32n");
        }

        draw(gridOffsetX, gridOffsetY) { // Added offsets to draw method
            const x = gridOffsetX + this.col * CELL_SIZE + CELL_SIZE / 2;
            const y = gridOffsetY + this.row * CELL_SIZE + CELL_SIZE / 2;

            ctx.strokeStyle = this.isConnected ? COLORS.pipeConnected : COLORS.pipeUnconnected;
            ctx.lineWidth = 8;
            ctx.lineCap = 'round';

            const openSides = this.getOpenSides();

            openSides.forEach(side => {
                ctx.beginPath();
                ctx.moveTo(x, y);
                switch (side) {
                    case 0: ctx.lineTo(x, y - CELL_SIZE / 2); break; // North
                    case 1: ctx.lineTo(x + CELL_SIZE / 2, y); break; // East
                    case 2: ctx.lineTo(x, y + CELL_SIZE / 2); break; // South
                    case 3: ctx.lineTo(x - CELL_SIZE / 2, y); break; // West
                }
                ctx.stroke();
            });

            if (this.isBroken) {
                ctx.strokeStyle = COLORS.inputNode; // Red for broken
                ctx.lineWidth = 3;
                ctx.beginPath();
                ctx.moveTo(x - CELL_SIZE / 4, y - CELL_SIZE / 4);
                ctx.lineTo(x + CELL_SIZE / 4, y + CELL_SIZE / 4);
                ctx.moveTo(x + CELL_SIZE / 4, y - CELL_SIZE / 4);
                ctx.lineTo(x - CELL_SIZE / 4, y + CELL_SIZE / 4);
                ctx.stroke();
            }
        }
    }

    // --- Game Logic Functions ---

    /**
     * Generates the initial puzzle grid, inputs, and outputs.
     * This version creates a fixed, SOLVED puzzle for demonstration,
     * connecting all four nodes in a single path.
     */
    function generatePuzzle() {
        grid = Array(NUM_GRID_CELLS).fill(0).map(() => Array(NUM_GRID_CELLS).fill(null));
        inputs = [];
        outputs = [];
        connections = [];
        totalMoves = 0;
        gameScore = 0;

        // Place inputs and outputs at corners
        inputs.push({ row: 0, col: 0, type: 'mic', targetType: 'headphone' }); // Top-left
        outputs.push({ row: NUM_GRID_CELLS - 1, col: NUM_GRID_CELLS - 1, type: 'headphone' }); // Bottom-right

        inputs.push({ row: NUM_GRID_CELLS - 1, col: 0, type: 'music', targetType: 'speaker' }); // Bottom-left
        outputs.push({ row: 0, col: NUM_GRID_CELLS - 1, type: 'speaker' }); // Top-right

        // --- PRE-SOLVED Puzzle Layout for a SINGLE, CONNECTED PATH ---
        // This path will connect (0,0) -> (0,5) -> (5,5) -> (5,0) -> (0,0) (or similar loop)
        // For simplicity, let's make a path that connects all four in a sequence:
        // (0,0) -> (0,5) -> (5,5) -> (5,0) -> (0,0) (using T-junctions or crosses to ensure connectivity)

        // Path segment 1: (0,0) (mic) connects to (0,5) (speaker)
        grid[0][0] = new PipeSegment(0, 0, 'STRAIGHT', 1); // E-W (from 0,0 to 0,1)
        grid[0][1] = new PipeSegment(0, 1, 'STRAIGHT', 1); // E-W
        grid[0][2] = new PipeSegment(0, 2, 'STRAIGHT', 1); // E-W
        grid[0][3] = new PipeSegment(0, 3, 'STRAIGHT', 1); // E-W
        grid[0][4] = new PipeSegment(0, 4, 'STRAIGHT', 1); // E-W
        grid[0][5] = new PipeSegment(0, 5, 'STRAIGHT', 1); // E-W (to 0,5 speaker)

        // Path segment 2: (0,5) (speaker) connects to (5,5) (headphone)
        // We'll use a T-junction at (0,5) to also connect downwards
        grid[0][5] = new PipeSegment(0, 5, 'T_JUNCTION', 2); // S-W-N (connects to 0,4 and 1,5)
        grid[1][5] = new PipeSegment(1, 5, 'STRAIGHT', 0); // N-S
        grid[2][5] = new PipeSegment(2, 5, 'STRAIGHT', 0); // N-S
        grid[3][5] = new PipeSegment(3, 5, 'STRAIGHT', 0); // N-S
        grid[4][5] = new PipeSegment(4, 5, 'STRAIGHT', 0); // N-S
        grid[5][5] = new PipeSegment(5, 5, 'STRAIGHT', 0); // N-S (to 5,5 headphone)

        // Path segment 3: (5,5) (headphone) connects to (5,0) (music)
        // Use a T-junction at (5,5) to also connect leftwards
        grid[5][5] = new PipeSegment(5, 5, 'T_JUNCTION', 1); // E-S-W (connects to 4,5 and 5,4)
        grid[5][4] = new PipeSegment(5, 4, 'STRAIGHT', 1); // E-W
        grid[5][3] = new PipeSegment(5, 3, 'STRAIGHT', 1); // E-W
        grid[5][2] = new PipeSegment(5, 2, 'STRAIGHT', 1); // E-W
        grid[5][1] = new PipeSegment(5, 1, 'STRAIGHT', 1); // E-W
        grid[5][0] = new PipeSegment(5, 0, 'STRAIGHT', 1); // E-W (to 5,0 music)

        // Path segment 4: (5,0) (music) connects back to (0,0) (mic)
        // Use a T-junction at (5,0) to also connect upwards
        grid[5][0] = new PipeSegment(5, 0, 'T_JUNCTION', 0); // N-E-S (connects to 5,1 and 4,0)
        grid[4][0] = new PipeSegment(4, 0, 'STRAIGHT', 0); // N-S
        grid[3][0] = new PipeSegment(3, 0, 'STRAIGHT', 0); // N-S
        grid[2][0] = new PipeSegment(2, 0, 'STRAIGHT', 0); // N-S
        grid[1][0] = new PipeSegment(1, 0, 'STRAIGHT', 0); // N-S
        // Connect to (0,0) which is already a STRAIGHT pipe, ensure compatibility
        grid[0][0] = new PipeSegment(0, 0, 'T_JUNCTION', 3); // W-N-E (connects to 0,1 and 1,0)


        // Fill remaining empty spots with random pipes (not crucial for solution, but fills the grid)
        const pipeTypes = ['STRAIGHT', 'CORNER', 'T_JUNCTION', 'CROSS'];
        for (let r = 0; r < NUM_GRID_CELLS; r++) {
            for (let c = 0; c < NUM_GRID_CELLS; c++) {
                if (grid[r][c] === null) {
                    const randomType = pipeTypes[Math.floor(Math.random() * pipeTypes.length)];
                    const randomRotation = Math.floor(Math.random() * PIPE_TYPES[randomType].length);
                    grid[r][c] = new PipeSegment(r, c, randomType, randomRotation);
                }
            }
        }

        // Ensure no broken nodes for this simplified puzzle
        gameConfig.initialBrokenNodes = 0; // Override config for this fixed puzzle
        inputs.forEach(input => {
            if (grid[input.row][input.col]) grid[input.row][input.col].isBroken = false;
        });
        outputs.forEach(output => {
            if (grid[output.row][output.col]) grid[output.row][output.col].isBroken = false;
        });

        // --- END PRE-SOLVED Puzzle Layout ---
    }

    /**
     * Checks if all four critical nodes (inputs and outputs) are connected in a single path.
     */
    function checkConnections() {
        connections = []; // Clear previous connections
        let allConnected = false;

        // Collect all critical nodes (inputs and outputs)
        const allCriticalNodes = [
            ...inputs.map(n => ({ row: n.row, col: n.col, type: n.type })),
            ...outputs.map(n => ({ row: n.row, col: n.col, type: n.type }))
        ];

        if (allCriticalNodes.length === 0) return false; // No nodes to connect

        // Start BFS from the first critical node
        const startNode = allCriticalNodes[0];
        let queue = [{ row: startNode.row, col: startNode.col, entrySide: -1 }];
        let visited = new Set(); // To prevent infinite loops and track visited cells
        let foundCriticalNodesCount = 0; // Count how many critical nodes we've found in this traversal

        // Map critical nodes to their string keys for quick lookup
        const criticalNodeKeys = new Set(allCriticalNodes.map(n => `${n.row},${n.col}`));

        while (queue.length > 0) {
            const { row, col, entrySide } = queue.shift();
            const cellKey = `${row},${col}`;

            if (visited.has(cellKey)) continue;
            visited.add(cellKey);

            // If this cell is a critical node, increment count
            if (criticalNodeKeys.has(cellKey)) {
                foundCriticalNodesCount++;
            }

            const currentPipe = grid[row][col];
            if (!currentPipe || currentPipe.isBroken) continue;

            const openSides = currentPipe.getOpenSides();

            // If coming from a side, ensure the pipe has an opening on that side
            if (entrySide !== -1 && !openSides.includes((entrySide + 2) % 4)) {
                continue; // Pipe doesn't connect from this entry side
            }

            // Explore neighbors
            openSides.forEach(exitSide => {
                if (exitSide === (entrySide + 2) % 4) return; // Don't go back immediately

                let nextRow = row, nextCol = col;
                switch (exitSide) {
                    case 0: nextRow--; break; // North
                    case 1: nextCol++; break; // East
                    case 2: nextRow++; break; // South
                    case 3: nextCol--; break; // West
                }

                // Check bounds
                if (nextRow >= 0 && nextRow < NUM_GRID_CELLS && nextCol >= 0 && nextCol < NUM_GRID_CELLS) {
                    const neighborPipe = grid[nextRow][nextCol];
                    if (neighborPipe && !neighborPipe.isBroken) {
                        const neighborOpenSides = neighborPipe.getOpenSides();
                        // Check if neighbor pipe has an opening that connects back to this cell
                        if (neighborOpenSides.includes((exitSide + 2) % 4)) {
                            queue.push({ row: nextRow, col: nextCol, entrySide: exitSide });
                        }
                    }
                }
            });
        }

        // The puzzle is solved if all critical nodes were found in this single traversal
        allConnected = (foundCriticalNodesCount === allCriticalNodes.length);

        // Update visual state of pipes
        for (let r = 0; r < NUM_GRID_CELLS; r++) {
            for (let c = 0; c < NUM_GRID_CELLS; c++) {
                if (grid[r][c]) {
                    // Mark as connected if the cell was visited during the successful traversal
                    grid[r][c].isConnected = allConnected && visited.has(`${r},${c}`);
                }
            }
        }

        // Play connect sound only if all are connected and it wasn't connected before
        const wasConnected = connections.some(c => c.connected); // Check previous state
        if (allConnected && !wasConnected) {
            if (connectSound) connectSound.triggerAttackRelease("C6", "16n");
        }

        connections.push({ input: startNode, connected: allConnected }); // Store result of this check

        return allConnected;
    }


    /**
     * Initializes the Day 4 game.
     * @param {HTMLCanvasElement} gameCanvasRef The canvas element.
     * @param {CanvasRenderingContext2D} context The 2D rendering context.
     * @param {function} showModalMessageBoxFunc Reference to main2.js's showMessageBox (modal).
     * @param {function} showGameStatusFunc Reference to main2.js's displayGameStatus (non-modal).
     * @param {function} updateScoreFunction Reference to main2.js's updateGlobalScore.
     * @param {function} restartGameFunction Reference to main2.js's restartCurrentGame.
     * @param {function} getScoreFunction Reference to main2.js's getGlobalScore.
     * @param {HTMLElement} tokensDisplayRef (unused for Day 4)
     * @param {HTMLElement} sackCapacityRef (unused for Day 4)
     * @param {HTMLElement} changeSackButtonRef (unused for Day 4)
     * @param {HTMLElement} startButtonRef Reference to startButton
     * @param {HTMLElement} pauseButtonRef Reference to pauseButton
     * @param {HTMLElement} restartButtonRef Reference to restartButton
     * @param {object} playerState Object containing player's superpower.
     * @param {number} currentDayLevel The current day level from main2.js.
     */
    window.Day4Game.initDay4Game = async function(gameCanvasRef, context, showModalMessageBoxFunc, showGameStatusFunc, updateScoreFunction, restartGameFunction, getScoreFunction, tokensDisplayRef, sackCapacityRef, changeSackButtonRef, startButtonRef, pauseButtonRef, restartButtonRef, playerState, currentDayLevel) {
        console.log("Day 4 Signal Routing Puzzle Initializing...");
        canvas = gameCanvasRef;
        ctx = context;
        showModalMessageBoxRef = showModalMessageBoxFunc;
        displayGameStatusRef = showGameStatusFunc;
        updateGlobalScoreRef = updateScoreFunction;
        restartCurrentGameRef = restartGameFunction;
        getGlobalScoreRef = getScoreFunction;

        // Hide Day 1/2/3 specific UI elements if they exist
        if (tokensDisplayRef && tokensDisplayRef.parentElement) tokensDisplayRef.parentElement.classList.add('hidden');
        if (sackCapacityRef && sackCapacityRef.parentElement) sackCapacityRef.parentElement.classList.add('hidden');
        if (changeSackButtonRef) changeSackButtonRef.classList.add('hidden');

        // Dynamically calculate CELL_SIZE based on the actual canvas dimensions
        // The canvas dimensions are managed by main2.js for responsiveness.
        // We ensure day4.js adapts to those dimensions.
        CELL_SIZE = Math.min(
            canvas.width / NUM_GRID_CELLS,
            (canvas.height - UI_SPACE_HEIGHT) / NUM_GRID_CELLS
        );
        // Ensure CELL_SIZE is an integer to avoid rendering artifacts
        CELL_SIZE = Math.floor(CELL_SIZE);

        day4Player.superpower = playerState.superpower;
        day4Player.level = currentDayLevel;

        // Apply game config based on player's superpower
        if (day4Player.superpower) {
            gameConfig = {
                IMPACT: {
                    timeLimit: 75,
                    initialBrokenNodes: 0,
                    scoreMultiplier: 1.2
                },
                ADAPT: {
                    timeLimit: 60,
                    initialBrokenNodes: 1,
                    scoreMultiplier: 1.0,
                    canUndo: true
                },
                EXPAND: {
                    timeLimit: 60,
                    initialBrokenNodes: 0,
                    scoreMultiplier: 1.1,
                    bonusTimePerConnection: 5
                }
            }[day4Player.superpower];
        }
        timeRemaining = gameConfig.timeLimit;

        // Dispose of existing Tone.js objects if they exist
        // This ensures a clean slate if initDay4Game is called multiple times
        if (rotateSound) { rotateSound.dispose(); rotateSound = null; }
        if (connectSound) { connectSound.dispose(); connectSound = null; }
        if (winSound) { winSound.dispose(); winSound = null; }
        if (loseSound) { loseSound.dispose(); loseSound = null; }
        if (backgroundMusicGain) { backgroundMusicGain.dispose(); backgroundMusicGain = null; }
        if (bgMusicSynth) { bgMusicSynth.dispose(); bgMusicSynth = null; }
        if (backgroundMusic) { backgroundMusic.dispose(); backgroundMusic = null; }

        // Initialize Tone.js audio context
        try {
            await Tone.start();
            console.log("Tone.js audio context started.");
        } catch (e) {
            console.error("Failed to start Tone.js audio context:", e);
            showModalMessageBoxRef('Audio Error', 'Could not start game audio. Please interact with the page first.', null, 3000);
        }

        // Setup sounds (ALWAYS re-create to ensure fresh state and connections)
        rotateSound = new Tone.Synth({
            oscillator: { type: "square" },
            envelope: { attack: 0.005, decay: 0.05, sustain: 0, release: 0.05 }
        }).toDestination();
        if (rotateSound && rotateSound.volume) { // Defensive check
            rotateSound.volume.value = -15;
        }

        connectSound = new Tone.Synth({
            oscillator: { type: "triangle" },
            envelope: { attack: 0.01, decay: 0.1, sustain: 0, release: 0.2 }
        }).toDestination();
        if (connectSound && connectSound.volume) { // Defensive check
            connectSound.volume.value = -10;
        }

        winSound = new Tone.PolySynth(Tone.Synth, {
            oscillator: { type: "sine" },
            envelope: { attack: 0.05, decay: 0.5, sustain: 0.1, release: 1 }
        }).toDestination();
        if (winSound && winSound.volume) { // Defensive check
            winSound.volume.value = -8;
        }

        loseSound = new Tone.NoiseSynth({
            noise: { type: "brown" },
            envelope: { attack: 0.01, decay: 0.3, sustain: 0, release: 0.5 }
        }).toDestination();
        if (loseSound && loseSound.volume) { // Defensive check
            loseSound.volume.value = -10;
        }

        // Re-create and connect bgMusicSynth and backgroundMusicGain
        backgroundMusicGain = new Tone.Gain(0).toDestination(); // Connect gain node to destination immediately
        if (backgroundMusicGain && backgroundMusicGain.volume) { // Defensive check for backgroundMusicGain.volume
            backgroundMusicGain.volume.value = -30; // Set initial volume for background music
        } else {
            console.error("ERROR: backgroundMusicGain or backgroundMusicGain.volume is undefined after creation. Cannot set volume.");
        }

        bgMusicSynth = new Tone.Synth({
            oscillator: { type: "sine" }, // Gentle sine wave for background
            envelope: { attack: 0.1, decay: 0.5, sustain: 0.2, release: 1 }
        }); // Create synth without immediate connection
        // Connect after creation
        if (bgMusicSynth && backgroundMusicGain) { // Ensure both exist before connecting
            bgMusicSynth.connect(backgroundMusicGain);
        } else {
            console.error("ERROR: bgMusicSynth or backgroundMusicGain is undefined, cannot connect bgMusicSynth.");
        }

        if (bgMusicSynth && bgMusicSynth.volume) { // Defensive check for bgMusicSynth.volume
            bgMusicSynth.volume.value = -10; // Base volume for background notes before gain
        } else {
            console.error("ERROR: bgMusicSynth or bgMusicSynth.volume is undefined after creation. Cannot set volume.");
        }


        backgroundMusic = new Tone.Loop(time => {
            // Trigger bgMusicSynth instead of rotateSound for background notes
            if (Math.random() > 0.5) {
                if (bgMusicSynth) bgMusicSynth.triggerAttackRelease("C3", "2n", time); // Defensive check
            } else {
                if (bgMusicSynth) bgMusicSynth.triggerAttackRelease("G2", "2n", time); // Defensive check
            }
        }, "1n");
        backgroundMusic.mute = true; // Start muted


        window.Day4Game.resetDay4Game(); // Call reset via the global object
        console.log("Day 4 Signal Routing Puzzle Initialized with superpower:", day4Player.superpower);
    };

    /**
     * Resets the game state for Day 4.
     */
    window.Day4Game.resetDay4Game = function() {
        gameRunning = false;
        generatePuzzle();
        timeRemaining = gameConfig.timeLimit;
        gameScore = 0;
        totalMoves = 0;
        lastFrameTime = 0;
        debugClickPoint = null; // Reset click debug point

        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
        }
        removeInputListeners();

        // Stop all sounds and clear transport
        if (backgroundMusic) backgroundMusic.stop(); // Stop the loop if it's running
        Tone.Transport.stop();
        Tone.Transport.cancel(); // Crucial to clear scheduled events

        drawGame(performance.now()); // Draw initial state, pass current time
    };

    /**
     * Starts the Day 4 game loop.
     */
    window.Day4Game.startGame = function() {
        if (gameRunning) return;
        gameRunning = true;
        addInputListeners(); // Add input listeners when game starts

        showModalMessageBoxRef('Day 4: The Signal Routing Puzzle', `Your mission is to connect all audio inputs to their correct outputs!
            <br><br>Click on a pipe segment to rotate it 90 degrees clockwise.
            <br>Inputs are <span style="color:${COLORS.inputNode};">red</span>, outputs are <span style="color:${COLORS.outputNode};">green</span>.
            <br>Connected pipes turn <span style="color:${COLORS.pipeConnected};">gold</span>.
            <br><br>Connect all signals before time runs out!
            <br>Your superpower (${day4Player.superpower}) will give you an edge!`, () => {
            lastFrameTime = performance.now(); // Initialize for the game loop
            animationFrameId = requestAnimationFrame(gameLoop);
            if (backgroundMusic) {
                backgroundMusic.mute = false;
                backgroundMusic.start(0);
            }
            Tone.Transport.start();
        });
        console.log("Day 4 Signal Routing Puzzle Started.");
    };

    /**
     * Pauses the Day 4 game.
     */
    window.Day4Game.pauseGame = function() {
        if (!gameRunning) return;
        gameRunning = false;
        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
        }
        removeInputListeners();

        if (backgroundMusic) backgroundMusic.stop();
        Tone.Transport.pause();

        showModalMessageBoxRef('Game Paused', 'The puzzle is on hold. Click OK to resume.', () => {
            lastFrameTime = performance.now(); // Reset lastFrameTime to prevent time jump
            animationFrameId = requestAnimationFrame(gameLoop);
            if (backgroundMusic) backgroundMusic.start(Tone.Transport.now());
            Tone.Transport.start();
        });
        console.log("Day 4 Signal Routing Puzzle Paused.");
    };

    /**
     * Restarts the Day 4 game.
     */
    window.Day4Game.restartGame = function() {
        gameRunning = false;
        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
        }
        removeInputListeners();

        if (backgroundMusic) backgroundMusic.stop();
        Tone.Transport.stop();

        showModalMessageBoxRef('Restarting Day 4', 'Starting the Signal Routing Puzzle from the beginning.', () => {
            restartCurrentGameRef(); // Trigger restart of current game in main2.js
        });
    };

    /**
     * The main game loop for Day 4.
     * @param {number} currentTime The current time in milliseconds.
     */
    function gameLoop(currentTime) {
        if (!gameRunning) return;

        const deltaTime = (currentTime - lastFrameTime) / 1000; // Convert to seconds
        lastFrameTime = currentTime;

        updateGame(deltaTime);
        drawGame(currentTime); // Pass currentTime to draw for debug point

        animationFrameId = requestAnimationFrame(gameLoop);
    }

    /**
     * Updates game state for Day 4.
     * @param {number} deltaTime Time elapsed since last frame in seconds.
     */
    function updateGame(deltaTime) {
        timeRemaining -= deltaTime;

        if (timeRemaining <= 0) {
            timeRemaining = 0;
            endGame(false); // Time's up!
            return;
        }

        const allConnected = checkConnections();
        if (allConnected) {
            endGame(true); // All connections made!
        }

        // Update score based on time remaining (if not yet won)
        gameScore = Math.max(0, Math.floor((gameConfig.timeLimit - timeRemaining) * gameConfig.scoreMultiplier));
        updateGlobalScoreRef(gameScore);
    }

    /**
     * Draws game elements for Day 4.
     * @param {number} currentTime Current time for debug point visibility.
     */
    function drawGame(currentTime) {
        ctx.clearRect(0, 0, canvas.width, canvas.height); // Clear canvas
        ctx.fillStyle = COLORS.background;
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // Calculate total grid dimensions
        const totalGridWidth = NUM_GRID_CELLS * CELL_SIZE;
        const totalGridHeight = NUM_GRID_CELLS * CELL_SIZE;

        // Calculate offsets to center the grid within the available space
        const availableGameHeight = canvas.height - UI_SPACE_HEIGHT;
        const gridOffsetX = (canvas.width - totalGridWidth) / 2;
        const gridOffsetY = (availableGameHeight - totalGridHeight) / 2;

        // Draw grid lines
        ctx.strokeStyle = COLORS.gridLine;
        ctx.lineWidth = 1;
        for (let i = 0; i <= NUM_GRID_CELLS; i++) {
            ctx.beginPath();
            ctx.moveTo(gridOffsetX + i * CELL_SIZE, gridOffsetY);
            ctx.lineTo(gridOffsetX + i * CELL_SIZE, gridOffsetY + NUM_GRID_CELLS * CELL_SIZE);
            ctx.stroke();
            ctx.beginPath();
            ctx.moveTo(gridOffsetX, gridOffsetY + i * CELL_SIZE);
            ctx.lineTo(gridOffsetX + NUM_GRID_CELLS * CELL_SIZE, gridOffsetY + i * CELL_SIZE);
            ctx.stroke();
        }

        // Draw pipe segments
        for (let r = 0; r < NUM_GRID_CELLS; r++) {
            for (let c = 0; c < NUM_GRID_CELLS; c++) {
                if (grid[r][c]) {
                    grid[r][c].draw(gridOffsetX, gridOffsetY); // Pass offsets to pipe draw method
                }
            }
        }

        // Draw inputs
        inputs.forEach(input => {
            const x = gridOffsetX + input.col * CELL_SIZE + CELL_SIZE / 2;
            const y = gridOffsetY + input.row * CELL_SIZE + CELL_SIZE / 2;
            ctx.fillStyle = COLORS.inputNode;
            ctx.beginPath();
            ctx.arc(x, y, CELL_SIZE / 4, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = COLORS.text;
            ctx.font = 'bold 12px Inter';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(input.type === 'mic' ? '🎤' : '🎵', x, y); // Use emojis for input types
        });

        // Draw outputs
        outputs.forEach(output => {
            const x = gridOffsetX + output.col * CELL_SIZE + CELL_SIZE / 2;
            const y = gridOffsetY + output.row * CELL_SIZE + CELL_SIZE / 2;
            ctx.fillStyle = COLORS.outputNode;
            ctx.beginPath();
            ctx.arc(x, y, CELL_SIZE / 4, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = COLORS.text;
            ctx.font = 'bold 12px Inter';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(output.type === 'headphone' ? '🎧' : '🔊', x, y); // Use emojis for output types
        });

        // Draw UI elements below the grid
        const uiY = canvas.height - UI_SPACE_HEIGHT + 20; // Position UI relative to bottom of canvas

        // Timer bar
        const timerBarWidth = canvas.width - 40;
        const timerBarHeight = 20;
        const timerBarX = 20;
        const timerBarY = uiY;

        ctx.fillStyle = COLORS.timerBackground;
        ctx.fillRect(timerBarX, timerBarY, timerBarWidth, timerBarHeight);

        const fillWidth = (timeRemaining / gameConfig.timeLimit) * timerBarWidth;
        ctx.fillStyle = COLORS.timerFill;
        ctx.fillRect(timerBarX, timerBarY, fillWidth, timerBarHeight);

        ctx.strokeStyle = COLORS.gridLine;
        ctx.lineWidth = 2;
        ctx.strokeRect(timerBarX, timerBarY, timerBarWidth, timerBarHeight);

        ctx.fillStyle = COLORS.text;
        ctx.font = 'bold 16px Inter';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(`Time: ${Math.floor(timeRemaining)}s`, canvas.width / 2, timerBarY + timerBarHeight / 2);

        // Score and Moves
        ctx.textAlign = 'left';
        ctx.fillText(`Score: ${Math.floor(gameScore)}`, 20, uiY + timerBarHeight + 30);
        ctx.textAlign = 'right';
        ctx.fillText(`Moves: ${totalMoves}`, canvas.width - 20, uiY + timerBarHeight + 30);

        // Draw debug click point if active
        if (debugClickPoint && currentTime < debugClickPoint.endTime) {
            ctx.fillStyle = 'red';
            ctx.beginPath();
            ctx.arc(debugClickPoint.x, debugClickPoint.y, 5, 0, Math.PI * 2);
            ctx.fill();
        } else {
            debugClickPoint = null; // Clear if time is up
        }
    }

    /**
     * Ends the Day 4 game.
     * @param {boolean} won True if the player won, false otherwise.
     */
    function endGame(won) {
        gameRunning = false;
        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
        }
        removeInputListeners();

        if (backgroundMusic) backgroundMusic.stop();
        Tone.Transport.stop();

        let messageTitle;
        let messageContent;

        if (won) {
            if (winSound) winSound.triggerAttackRelease(["C5", "E5", "G5"], "1s");
            messageTitle = 'Puzzle Solved!';
            messageContent = `Congratulations! You successfully routed all signals!
                <br><br>Your final score for Day 4: ${Math.floor(gameScore)}.
                <br>Total moves: ${totalMoves}.
                <br><br><span style="font-size: 28px;">The code to enter today's prize draw is: <b>ADAPT 660T ANC</b></span>`;
        } else {
            if (loseSound) loseSound.triggerAttackRelease("1s");
            messageTitle = 'Time\'s Up!';
            messageContent = `Oh no! You ran out of time. Keep practicing your signal routing!
                <br><br>Your final score for Day 4: ${Math.floor(gameScore)}.
                <br>Total moves: ${totalMoves}.
                <br><br><span style="font-size: 28px;">The code to enter today's prize draw is: <b>ADAPT 660T ANC</b></span>`;
        }

        showModalMessageBoxRef(messageTitle, messageContent, () => {
            if (won) {
                updateGlobalScoreRef(Math.floor(gameScore)); // Ensure final score is added
                window.advanceToNextLevel(); // Call global function to advance to next day
            } else {
                // Stay on game screen, allow restart
                console.log("Game Over message dismissed. Player can restart.");
            }
        });
    }

    // --- Input Handling ---
    function handleCanvasClick(event) {
        if (!gameRunning) return;

        const rect = canvas.getBoundingClientRect();
        const mouseX = event.clientX - rect.left;
        const mouseY = event.clientY - rect.top;

        // Calculate total grid dimensions
        const totalGridWidth = NUM_GRID_CELLS * CELL_SIZE;
        const totalGridHeight = NUM_GRID_CELLS * CELL_SIZE;

        // Calculate offsets to center the grid within the available space
        const availableGameHeight = canvas.height - UI_SPACE_HEIGHT;
        const gridOffsetX = (canvas.width - totalGridWidth) / 2;
        const gridOffsetY = (availableGameHeight - totalGridHeight) / 2;

        // Adjust click coordinates by the grid offset
        const adjustedMouseX = mouseX - gridOffsetX;
        const adjustedMouseY = mouseY - gridOffsetY;

        const row = Math.floor(adjustedMouseY / CELL_SIZE);
        const col = Math.floor(adjustedMouseX / CELL_SIZE);

        // Store debug click point for drawing (use raw mouseX, mouseY to show actual click location)
        debugClickPoint = { x: mouseX, y: mouseY, endTime: performance.now() + 500 }; // Show for 0.5 seconds

        if (row >= 0 && row < NUM_GRID_CELLS && col >= 0 && col < NUM_GRID_CELLS) {
            const clickedPipe = grid[row][col];
            if (clickedPipe) { // Check if it's not null/undefined first
                if (!clickedPipe.isBroken) {
                    // Check if rotate is actually a function
                    if (typeof clickedPipe.rotate === 'function') {
                        clickedPipe.rotate();
                    } else {
                        console.error(`ERROR: clickedPipe at (${row}, ${col}) is an object but .rotate is not a function. Object:`, clickedPipe);
                        displayGameStatusRef("Invalid pipe segment. Cannot rotate.", 1500);
                    }
                } else {
                    displayGameStatusRef("This pipe is broken and cannot be rotated!", 1500);
                }
            } else {
                displayGameStatusRef("No pipe segment here!", 1500);
            }
        } else {
            displayGameStatusRef("Click within the puzzle grid!", 1500);
        }
    }

    function addInputListeners() {
        canvas.addEventListener('click', handleCanvasClick);
        console.log("Day 4 input listeners added.");
    }

    function removeInputListeners() {
        canvas.removeEventListener('click', handleCanvasClick);
        console.log("Day 4 input listeners removed.");
    }

})(); // End of IIFE

console.log("Day4.js script loaded.");
