/**
 * day4-tetris.js
 *
 * Contains the specific game logic for Day 4: Sound Block Harmony (Tetris-like Puzzle).
 * This module is designed to be imported and controlled by main2.js.
 *
 * @version 1.0.4
 * @date 2025-07-22
 * @author Gemini Assistant
 */

// Define a global object to hold Day4Game functions immediately
window.Day4Game = window.Day4Game || {};
console.log("window.Day4Game namespace defined and accessible for Tetris game.");

(function() {

    // --- Tone.js Audio Setup ---
    let backgroundMusicSynth;
    let backgroundMusicLoop;
    let blockFallSynth; // For when a block lands
    let lineClearSynth; // For when a line is cleared (harmony feedback)
    let gameOverNoise;  // For game over sound

    // Notes associated with each Tetrimino type for harmony calculation
    const BLOCK_NOTES = {
        'I': 'C4', // Pure, fundamental
        'O': 'D4', // Solid, round
        'T': 'E4', // Balanced
        'L': 'F4', // Leading
        'J': 'G4', // Grounding
        'S': 'A4', // Sharp
        'Z': 'B4'  // Zigzag
    };

    // --- Game Configuration ---
    const BOARD_WIDTH = 10;
    const BOARD_HEIGHT = 20; // Standard Tetris board size
    const BLOCK_SIZE = 30; // Pixel size of each block cell
    const PREVIEW_SIZE = 4; // Size of the preview grid

    // Colors (EPOS theme)
    const COLORS = {
        background: '#002B32', // Dark Petrol
        gridLine: '#004D55', // Lighter Petrol for grid lines
        blockI: '#A7D9D3',   // EPOS Mint
        blockO: '#FFD700',   // Gold Accent
        blockT: '#B22222',   // Festive Red
        blockL: '#4CAF50',   // Green
        blockJ: '#00BFFF',   // Deep Sky Blue
        blockS: '#FF4500',   // Orange Red
        blockZ: '#8A2BE2',   // Blue Violet
        text: '#FFFFFF',
        gameOver: '#B22222', // Red for game over
        harmonyPerfect: '#00FF00', // Bright green for perfect harmony
        harmonyGood: '#A7D9D3',    // EPOS Mint for good harmony
        harmonyBad: '#FF0000',     // Red for disharmony
    };

    // Tetrimino shapes (rotations)
    // Each shape is defined by a 4x4 grid, with 1s indicating block cells
    const TETROMINOS = {
        'I': {
            shape: [
                [0, 0, 0, 0],
                [1, 1, 1, 1],
                [0, 0, 0, 0],
                [0, 0, 0, 0]
            ],
            color: COLORS.blockI
        },
        'J': {
            shape: [
                [1, 0, 0],
                [1, 1, 1],
                [0, 0, 0]
            ],
            color: COLORS.blockJ
        },
        'L': {
            shape: [
                [0, 0, 1],
                [1, 1, 1],
                [0, 0, 0]
            ],
            color: COLORS.blockL
        },
        'O': {
            shape: [
                [1, 1],
                [1, 1]
            ],
            color: COLORS.blockO
        },
        'S': {
            shape: [
                [0, 1, 1],
                [1, 1, 0],
                [0, 0, 0]
            ],
            color: COLORS.blockS
        },
        'T': {
            shape: [
                [0, 1, 0],
                [1, 1, 1],
                [0, 0, 0]
            ],
            color: COLORS.blockT
        },
        'Z': {
            shape: [
                [1, 1, 0],
                [0, 1, 1],
                [0, 0, 0]
            ],
            color: COLORS.blockZ
        }
    };
    const TETROMINO_TYPES = Object.keys(TETROMINOS);

    // --- Internal Game State ---
    let canvas;
    let ctx;
    let showModalMessageBoxRef;
    let displayGameStatusRef;
    let updateGlobalScoreRef;
    let restartCurrentGameRef;
    let getGlobalScoreRef;

    let day4Player = {
        superpower: null,
        level: 4
    };

    let board = []; // 2D array representing the game board
    let currentBlock;
    let nextBlock;
    let score = 0;
    let linesCleared = 0;
    let level = 1; // Game level (increases falling speed based on lines cleared)
    let difficultyLevel = 1; // New: Overall difficulty based on time
    let dropInterval = 1000; // Milliseconds per drop (decreases with level and difficulty)
    let lastDropTime = 0;
    let gameRunning = false;
    let animationFrameId;
    let lastFrameTime = 0; // For delta time calculation

    let difficultyIncreaseInterval = 15000; // Increase difficulty every 15 seconds
    let lastDifficultyIncreaseTime = 0;

    let keys = {}; // For keyboard input
    let touchStartX = 0;
    let touchStartY = 0;
    let touchEndX = 0;
    let touchEndY = 0;
    const SWIPE_THRESHOLD = 30; // Min pixels for a swipe

    // --- Game Object Classes ---
    class Block {
        constructor(type) {
            this.type = type;
            this.shape = JSON.parse(JSON.stringify(TETROMINOS[type].shape)); // Deep copy
            this.color = TETROMINOS[type].color;
            this.note = BLOCK_NOTES[type]; // Musical note for this block type
            this.x = Math.floor(BOARD_WIDTH / 2) - Math.floor(this.shape[0].length / 2);
            this.y = 0; // Start at the top
        }

        rotate() {
            const newShape = [];
            const rows = this.shape.length;
            const cols = this.shape[0].length;

            for (let c = 0; c < cols; c++) {
                newShape.push([]);
                for (let r = rows - 1; r >= 0; r--) {
                    newShape[c].push(this.shape[r][c]);
                }
            }
            this.shape = newShape;
        }

        draw(ctx, offsetX = 0, offsetY = 0) {
            ctx.fillStyle = this.color;
            for (let r = 0; r < this.shape.length; r++) {
                for (let c = 0; c < this.shape[r].length; c++) {
                    if (this.shape[r][c]) {
                        ctx.fillRect(
                            (this.x + c) * BLOCK_SIZE + offsetX,
                            (this.y + r) * BLOCK_SIZE + offsetY,
                            BLOCK_SIZE,
                            BLOCK_SIZE
                        );
                        ctx.strokeStyle = COLORS.background; // Border for blocks
                        ctx.lineWidth = 2;
                        ctx.strokeRect(
                            (this.x + c) * BLOCK_SIZE + offsetX,
                            (this.y + r) * BLOCK_SIZE + offsetY,
                            BLOCK_SIZE,
                            BLOCK_SIZE
                        );
                    }
                }
            }
        }
    }

    // --- Game Logic Functions ---

    /**
     * Initializes the game board with empty cells.
     */
    function initBoard() {
        board = Array(BOARD_HEIGHT).fill(0).map(() => Array(BOARD_WIDTH).fill(null));
    }

    /**
     * Spawns a new random block.
     */
    function spawnBlock() {
        const randomType = TETROMINO_TYPES[Math.floor(Math.random() * TETROMINO_TYPES.length)];
        currentBlock = nextBlock || new Block(randomType); // Use nextBlock if available
        const nextRandomType = TETROMINO_TYPES[Math.floor(Math.random() * TETROMINO_TYPES.length)];
        nextBlock = new Block(nextRandomType);

        // Check for game over condition immediately on spawn
        if (checkCollision(currentBlock.x, currentBlock.y, currentBlock.shape)) {
            endGame(false); // Game over
        }
    }

    /**
     * Checks if a block at a given position and shape collides with boundaries or other blocks.
     * @param {number} x - X position of the block.
     * @param {number} y - Y position of the block.
     * @param {Array<Array<number>>} shape - Shape of the block.
     * @returns {boolean} True if collision, false otherwise.
     */
    function checkCollision(x, y, shape) {
        for (let r = 0; r < shape.length; r++) {
            for (let c = 0; c < shape[r].length; c++) {
                if (shape[r][c]) { // If it's a part of the block
                    const boardX = x + c;
                    const boardY = y + r;

                    // Check boundaries
                    if (boardX < 0 || boardX >= BOARD_WIDTH || boardY >= BOARD_HEIGHT) {
                        return true; // Collision with side or bottom boundary
                    }
                    if (boardY < 0) { // Allow blocks to start above the board
                        continue;
                    }
                    // Check collision with existing blocks on the board
                    if (board[boardY][boardX] !== null) {
                        return true; // Collision with another block
                    }
                }
            }
        }
        return false;
    }

    /**
     * Merges the current falling block into the board.
     */
    function mergeBlock() {
        for (let r = 0; r < currentBlock.shape.length; r++) {
            for (let c = 0; c < currentBlock.shape[r].length; c++) {
                if (currentBlock.shape[r][c]) {
                    const boardX = currentBlock.x + c;
                    const boardY = currentBlock.y + r;
                    if (boardY >= 0) { // Only merge if within board bounds
                        board[boardY][boardX] = { color: currentBlock.color, note: currentBlock.note };
                    }
                }
            }
        }
        if (blockFallSynth) blockFallSynth.triggerAttackRelease("C3", "8n"); // Play sound when block lands
    }

    /**
     * Clears full lines and calculates harmony score.
     * @returns {number} Number of lines cleared.
     */
    function clearLines() {
        let linesToClear = [];
        let clearedLineNotes = []; // Store notes of blocks in cleared lines

        for (let r = 0; r < BOARD_HEIGHT; r++) {
            if (board[r].every(cell => cell !== null)) {
                linesToClear.push(r);
                // Collect notes from this cleared line
                clearedLineNotes.push(board[r].map(cell => cell.note));
            }
        }

        linesToClear.forEach(row => {
            // Remove the row
            board.splice(row, 1);
            // Add a new empty row at the top
            board.unshift(Array(BOARD_WIDTH).fill(null));
        });

        if (linesToClear.length > 0) {
            linesCleared += linesToClear.length;
            score += linesToClear.length * 100 * level; // Base score for clearing lines

            // Calculate harmony for cleared lines
            clearedLineNotes.forEach(notesInLine => {
                calculateAndPlayHarmony(notesInLine);
            });

            if (lineClearSynth) lineClearSynth.triggerAttackRelease("G5", "16n"); // General line clear sound
        }
        return linesToClear.length;
    }

    /**
     * Calculates harmony from a set of notes and plays a corresponding sound.
     * @param {Array<string>} notes - Array of musical notes (e.g., ['C4', 'E4', 'G4']).
     */
    function calculateAndPlayHarmony(notes) {
        if (notes.length === 0) return;

        // Simple harmony check: count unique notes and check for common chords
        const uniqueNotes = new Set(notes);
        let harmonyType = 'bad'; // Default to bad harmony

        // Example: Check for C Major chord (C, E, G)
        const cMajorChord = ['C4', 'E4', 'G4'];
        const isCMajor = cMajorChord.every(note => uniqueNotes.has(note));

        if (isCMajor && uniqueNotes.size <= 3) { // Perfect harmony if it's exactly C major or a subset
            harmonyType = 'perfect';
            score += 500 * level; // Bonus for perfect harmony
            displayGameStatusRef('Perfect Harmony!', 1000);
            if (lineClearSynth) lineClearSynth.triggerAttackRelease(['C5', 'E5', 'G5'], "8n");
        } else if (uniqueNotes.size <= 3) { // Good harmony if few unique notes (simple intervals)
            harmonyType = 'good';
            score += 200 * level; // Bonus for good harmony
            displayGameStatusRef('Good Harmony!', 1000);
            if (lineClearSynth) lineClearSynth.triggerAttackRelease(['C5', 'G5'], "8n");
        } else { // Many unique notes, likely dissonant
            displayGameStatusRef('Disharmony!', 1000);
            if (lineClearSynth) lineClearSynth.triggerAttackRelease(['C4', 'C#4', 'D4'], "8n"); // Dissonant cluster
        }
        console.log(`Harmony Type: ${harmonyType}, Notes: ${notes.join(', ')}`);
    }


    /**
     * Updates the game level and drop interval based on lines cleared.
     */
    function updateLevel() {
        level = Math.floor(linesCleared / 10) + 1; // Increase level every 10 lines
        // Drop interval is now also affected by overall difficultyLevel
        dropInterval = Math.max(100, 1000 - (level - 1) * 70 - (difficultyLevel - 1) * 50); // Min drop interval 100ms
        console.log(`Lines Level: ${level}, Drop Interval: ${dropInterval}ms`);
    }

    /**
     * The main game loop for Day 4 (Sound Block Harmony).
     * @param {number} currentTime The current time in milliseconds.
     */
    function gameLoop(currentTime) {
        if (!gameRunning) return;

        const deltaTime = currentTime - lastFrameTime;
        lastFrameTime = currentTime;

        // Difficulty increase logic based on time
        if (currentTime - lastDifficultyIncreaseTime > difficultyIncreaseInterval) {
            difficultyLevel++;
            // Make blocks fall faster
            dropInterval = Math.max(100, dropInterval - 50); // Decrease by 50ms, minimum 100ms
            lastDifficultyIncreaseTime = currentTime;
            displayGameStatusRef(`Difficulty Level: ${difficultyLevel}`, 1500);
            console.log(`Overall Difficulty: ${difficultyLevel}, Adjusted Drop Interval: ${dropInterval}ms`);
        }

        // Automatic block drop
        if (currentTime - lastDropTime > dropInterval) {
            if (!checkCollision(currentBlock.x, currentBlock.y + 1, currentBlock.shape)) {
                currentBlock.y++;
            } else {
                mergeBlock();
                const cleared = clearLines();
                if (cleared > 0) {
                    updateLevel(); // Update level based on lines cleared
                }
                spawnBlock();
            }
            lastDropTime = currentTime;
        }

        drawGame(); // Draw everything

        animationFrameId = requestAnimationFrame(gameLoop);
    }

    /**
     * Draws all game elements for Day 4 (Sound Block Harmony).
     */
    function drawGame() {
        if (!ctx || !canvas) return;

        // Clear canvas and draw background
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = COLORS.background;
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // Calculate board offset to center it vertically within the available space
        const boardHeightPx = BOARD_HEIGHT * BLOCK_SIZE; // 600px
        const uiTopSpace = 120; // Space for Score, Lines, Level, Difficulty
        const uiBottomSpace = 30; // Small padding at bottom
        const availableHeightForBoard = canvas.height - uiTopSpace - uiBottomSpace;

        const boardOffsetX = (canvas.width - BOARD_WIDTH * BLOCK_SIZE) / 2;
        // Vertically center the board within the remaining space after top UI
        const boardOffsetY = uiTopSpace + (availableHeightForBoard - boardHeightPx) / 2;


        // Draw board grid lines
        ctx.strokeStyle = COLORS.gridLine;
        ctx.lineWidth = 1;
        for (let r = 0; r <= BOARD_HEIGHT; r++) {
            ctx.beginPath();
            ctx.moveTo(boardOffsetX, boardOffsetY + r * BLOCK_SIZE);
            ctx.lineTo(boardOffsetX + BOARD_WIDTH * BLOCK_SIZE, boardOffsetY + r * BLOCK_SIZE);
            ctx.stroke();
        }
        for (let c = 0; c <= BOARD_WIDTH; c++) {
            ctx.beginPath();
            ctx.moveTo(boardOffsetX + c * BLOCK_SIZE, boardOffsetY);
            ctx.lineTo(boardOffsetX + c * BLOCK_SIZE, boardOffsetY + BOARD_HEIGHT * BLOCK_SIZE);
            ctx.stroke();
        }

        // Draw existing blocks on the board
        for (let r = 0; r < BOARD_HEIGHT; r++) {
            for (let c = 0; c < BOARD_WIDTH; c++) {
                if (board[r][c]) {
                    ctx.fillStyle = board[r][c].color;
                    ctx.fillRect(
                        (c * BLOCK_SIZE) + boardOffsetX,
                        (r * BLOCK_SIZE) + boardOffsetY,
                        BLOCK_SIZE,
                        BLOCK_SIZE
                    );
                    ctx.strokeStyle = COLORS.background;
                    ctx.lineWidth = 2;
                    ctx.strokeRect(
                        (c * BLOCK_SIZE) + boardOffsetX,
                        (r * BLOCK_SIZE) + boardOffsetY,
                        BLOCK_SIZE,
                        BLOCK_SIZE
                    );
                }
            }
        }

        // Draw current falling block
        if (currentBlock) {
            currentBlock.draw(ctx, boardOffsetX, boardOffsetY);
        }

        // Draw UI elements (Score, Lines, Level, Difficulty)
        ctx.fillStyle = COLORS.text;
        ctx.font = 'bold 24px Inter';
        ctx.textAlign = 'left';
        ctx.fillText(`Score: ${score}`, 20, 40);
        ctx.fillText(`Lines: ${linesCleared}`, 20, 70);
        ctx.fillText(`Level: ${level}`, 20, 100); // Level based on lines cleared

        // Draw Overall Difficulty Level (centered at top)
        ctx.textAlign = 'center';
        ctx.fillText(`Difficulty: ${difficultyLevel}`, canvas.width / 2, 40);

        // Draw Next Block preview (top right)
        ctx.font = 'bold 18px Inter';
        ctx.textAlign = 'right';
        ctx.fillText('NEXT', canvas.width - 20, 40);
        if (nextBlock) {
            // Calculate position for preview block
            const previewOffsetX = canvas.width - (PREVIEW_SIZE * BLOCK_SIZE) - 20;
            const previewOffsetY = 60;
            nextBlock.draw(ctx, previewOffsetX, previewOffsetY);
        }
    }

    /**
     * Ends the game (Game Over or Win).
     * @param {boolean} won True if won, false if game over.
     */
    function endGame(won) {
        gameRunning = false;
        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
        }
        removeInputListeners();

        // Stop all sounds
        if (backgroundMusicLoop) backgroundMusicLoop.stop();
        if (backgroundMusicSynth) backgroundMusicSynth.dispose();
        if (blockFallSynth) blockFallSynth.dispose();
        if (lineClearSynth) lineClearSynth.dispose();
        if (gameOverNoise) gameOverNoise.triggerAttackRelease("1s"); // Play game over sound
        Tone.Transport.stop();

        let messageTitle;
        let messageContent;

        if (won) {
            messageTitle = 'Day 4 Complete!';
            messageContent = `Congratulations! You mastered the Sound Block Harmony!
                <br><br>Final Score: ${score}. Lines Cleared: ${linesCleared}.
                <br><br><span style="font-size: 28px;">The code to enter today's prize draw is: <b>ADAPT 660T ANC</b></span>`;
        } else {
            messageTitle = 'Game Over!';
            messageContent = `Blocks piled too high! Your harmony quest for Day 4 ends here.
                <br><br>Final Score: ${score}. Lines Cleared: ${linesCleared}.
                <br><br><span style="font-size: 28px;">The code to enter today's prize draw is: <b>ADAPT 660T ANC</b></span>`;
        }

        showModalMessageBoxRef(messageTitle, messageContent, () => {
            if (won) {
                updateGlobalScoreRef(score);
                window.advanceToNextLevel();
            } else {
                // Allow player to restart from game over screen
                console.log("Game Over message dismissed. Player can restart.");
            }
        });
    }

    // --- Input Handling ---

    /**
     * Handles keyboard input for Tetris controls.
     * @param {KeyboardEvent} e - The keyboard event.
     */
    function handleKeyDown(e) {
        if (!gameRunning) return;

        const currentX = currentBlock.x;
        const currentY = currentBlock.y;
        const currentShape = currentBlock.shape;

        let moved = false;
        switch (e.key) {
            case 'ArrowLeft':
                if (!checkCollision(currentX - 1, currentY, currentShape)) {
                    currentBlock.x--;
                    moved = true;
                }
                break;
            case 'ArrowRight':
                if (!checkCollision(currentX + 1, currentY, currentShape)) {
                    currentBlock.x++;
                    moved = true;
                }
                break;
            case 'ArrowDown':
                if (!checkCollision(currentX, currentY + 1, currentShape)) {
                    currentBlock.y++;
                    score += 1; // Soft drop bonus
                    moved = true;
                }
                lastDropTime = performance.now(); // Reset drop timer on soft drop
                break;
            case 'ArrowUp':
                const rotatedShape = JSON.parse(JSON.stringify(currentShape)); // Create a copy
                currentBlock.rotate(); // Rotate the block
                if (checkCollision(currentX, currentY, currentBlock.shape)) {
                    // If collision after rotation, try to kick the block
                    if (!tryKick(currentBlock)) {
                        currentBlock.shape = rotatedShape; // Revert if kick fails
                    }
                }
                moved = true;
                break;
            case ' ': // Spacebar for hard drop
                while (!checkCollision(currentX, currentBlock.y + 1, currentShape)) {
                    currentBlock.y++;
                    score += 2; // Hard drop bonus
                }
                mergeBlock();
                const cleared = clearLines();
                if (cleared > 0) {
                    updateLevel();
                }
                spawnBlock();
                lastDropTime = performance.now(); // Reset drop timer on hard drop
                moved = true;
                break;
        }
        if (moved) {
            drawGame(); // Redraw immediately after movement
        }
    }

    /**
     * Attempts to "kick" a block after rotation if it collides.
     * Implements a simplified Super Rotation System (SRS) kick logic.
     * @param {Block} block The block to kick.
     * @returns {boolean} True if kick was successful, false otherwise.
     */
    function tryKick(block) {
        const kickTests = [
            { dx: 0, dy: 0 },   // No kick
            { dx: 1, dy: 0 },   // Right
            { dx: -1, dy: 0 },  // Left
            { dx: 0, dy: -1 },  // Up
            { dx: 1, dy: -1 },  // Right and up
            { dx: -1, dy: -1 }  // Left and up
        ];

        for (const test of kickTests) {
            const newX = block.x + test.dx;
            const newY = block.y + test.dy;
            if (!checkCollision(newX, newY, block.shape)) {
                block.x = newX;
                block.y = newY;
                return true; // Kick successful
            }
        }
        return false; // No kick worked
    }

    /**
     * Handles touch start events for swipe detection.
     * @param {TouchEvent} e - The touch event.
     */
    function handleTouchStart(e) {
        e.preventDefault(); // Prevent scrolling
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
    }

    /**
     * Handles touch move events for swipe detection.
     * @param {TouchEvent} e - The touch event.
     */
    function handleTouchMove(e) {
        e.preventDefault(); // Prevent scrolling
        touchEndX = e.touches[0].clientX;
        touchEndY = e.touches[0].clientY;
    }

    /**
     * Handles touch end events for swipe actions.
     */
    function handleTouchEnd() {
        if (!gameRunning) return;

        const deltaX = touchEndX - touchStartX;
        const deltaY = touchEndY - touchStartY;

        const currentX = currentBlock.x;
        const currentY = currentBlock.y;
        const currentShape = currentBlock.shape;

        let moved = false;

        if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > SWIPE_THRESHOLD) {
            // Horizontal swipe
            if (deltaX > 0) { // Swipe Right
                if (!checkCollision(currentX + 1, currentY, currentShape)) {
                    currentBlock.x++;
                    moved = true;
                }
            } else { // Swipe Left
                if (!checkCollision(currentX - 1, currentY, currentShape)) {
                    currentBlock.x--;
                    moved = true;
                }
            }
        } else if (Math.abs(deltaY) > Math.abs(deltaX) && Math.abs(deltaY) > SWIPE_THRESHOLD) {
            // Vertical swipe
            if (deltaY > 0) { // Swipe Down (soft drop)
                if (!checkCollision(currentX, currentY + 1, currentShape)) {
                    currentBlock.y++;
                    score += 1;
                    moved = true;
                }
                lastDropTime = performance.now();
            } else { // Swipe Up (rotate)
                const rotatedShape = JSON.parse(JSON.stringify(currentShape));
                currentBlock.rotate();
                if (checkCollision(currentX, currentY, currentBlock.shape)) {
                    if (!tryKick(currentBlock)) {
                        currentBlock.shape = rotatedShape;
                    }
                }
                moved = true;
            }
        } else if (Math.abs(deltaX) <= SWIPE_THRESHOLD && Math.abs(deltaY) <= SWIPE_THRESHOLD) {
            // Tap (hard drop for simplicity, or could be another action)
            while (!checkCollision(currentX, currentBlock.y + 1, currentShape)) {
                currentBlock.y++;
                score += 2;
            }
            mergeBlock();
            const cleared = clearLines();
            if (cleared > 0) {
                updateLevel();
            }
            spawnBlock();
            lastDropTime = performance.now();
            moved = true;
        }

        if (moved) {
            drawGame();
        }

        // Reset touch coordinates
        touchStartX = 0;
        touchStartY = 0;
        touchEndX = 0;
        touchEndY = 0;
    }


    function addInputListeners() {
        document.addEventListener('keydown', handleKeyDown);
        canvas.addEventListener('touchstart', handleTouchStart);
        canvas.addEventListener('touchmove', handleTouchMove);
        canvas.addEventListener('touchend', handleTouchEnd);
        console.log("Day 4 Tetris input listeners added.");
    }

    function removeInputListeners() {
        document.removeEventListener('keydown', handleKeyDown);
        canvas.removeEventListener('touchstart', handleTouchStart);
        canvas.removeEventListener('touchmove', handleTouchMove);
        canvas.removeEventListener('touchend', handleTouchEnd);
        console.log("Day 4 Tetris input listeners removed.");
    }

    // --- Exported Functions for main2.js ---

    /**
     * Initializes the Day 4 game.
     * @param {HTMLCanvasElement} gameCanvasRef The canvas element.
     * @param {CanvasRenderingContext2D} context The 2D rendering context.
     * @param {function} showModalMessageBoxFunc Reference to main2.js's showMessageBox (modal).
     * @param {function} displayGameStatusFunc Reference to main2.js's displayGameStatus (non-modal).
     * @param {function} updateScoreFunction Reference to main2.js's updateGlobalScore.
     * @param {function} restartGameFunction Reference to main2.js's restartCurrentGame.
     * @param {function} getScoreFunction Reference to main2.js's getGlobalScore.
     * @param {HTMLElement} tokensDisplayRef (unused for Day 4)
     * @param {HTMLElement} sackCapacityRef (unused for Day 4)
     * @param {HTMLElement} changeSackButtonRef (unused for Day 4)
     * @param {HTMLElement} startButtonRef (unused for Day 4)
     * @param {HTMLElement} pauseButtonRef (unused for Day 4)
     * @param {HTMLElement} restartButtonRef (unused for Day 4)
     * @param {object} playerState Object containing player's superpower.
     * @param {number} currentDayLevel The current day level from main2.js.
     */
    window.Day4Game.initDay4Game = async function(gameCanvasRef, context, showModalMessageBoxFunc, showGameStatusFunc, updateScoreFunction, restartGameFunction, getScoreFunction, tokensDisplayRef, sackCapacityRef, changeSackButtonRef, startButtonRef, pauseButtonRef, restartButtonRef, playerState, currentDayLevel) {
        console.log("Day 4 Sound Block Harmony Initializing...");
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

        day4Player.superpower = playerState.superpower;
        day4Player.level = currentDayLevel;

        // Ensure canvas pixel dimensions match its current CSS size for clear drawing
        canvas.width = canvas.clientWidth;
        canvas.height = canvas.clientHeight;


        // Initialize Tone.js audio context
        try {
            await Tone.start();
            console.log("Tone.js audio context started.");
        } catch (e) {
            console.error("Failed to start Tone.js audio context:", e);
            showModalMessageBoxRef('Audio Error', 'Could not start game audio. Please interact with the page first.', null, 3000);
        }

        // Setup Tone.js instruments
        if (!backgroundMusicSynth) {
            backgroundMusicSynth = new Tone.PolySynth(Tone.Synth, {
                oscillator: { type: "triangle" },
                envelope: { attack: 0.05, decay: 0.1, sustain: 0.3, release: 0.8 }
            }).toDestination();
            backgroundMusicSynth.volume.value = -20;
        }

        if (!backgroundMusicLoop) {
            backgroundMusicLoop = new Tone.Loop(time => {
                backgroundMusicSynth.triggerAttackRelease(["C3", "E3", "G3"], "2n", time);
            }, "1n");
            backgroundMusicLoop.mute = true; // Start muted
        }

        if (!blockFallSynth) {
            blockFallSynth = new Tone.Synth({
                oscillator: { type: "square" },
                envelope: { attack: 0.005, decay: 0.05, sustain: 0, release: 0.1 }
            }).toDestination();
            blockFallSynth.volume.value = -15;
        }

        if (!lineClearSynth) {
            lineClearSynth = new Tone.PolySynth(Tone.Synth, {
                oscillator: { type: "sine" },
                envelope: { attack: 0.01, decay: 0.2, sustain: 0.05, release: 0.3 }
            }).toDestination();
            lineClearSynth.volume.value = -10;
        }

        if (!gameOverNoise) {
            gameOverNoise = new Tone.NoiseSynth({
                noise: { type: "pink" },
                envelope: { attack: 0.01, decay: 0.5, sustain: 0, release: 1 }
            }).toDestination();
            gameOverNoise.volume.value = -5;
        }

        // Add resize listener
        window.addEventListener('resize', onWindowResize);

        window.Day4Game.resetDay4Game();
        console.log("Day 4 Sound Block Harmony Initialized with superpower:", day4Player.superpower);
    };

    /**
     * Resets the game state for Day 4.
     */
    window.Day4Game.resetDay4Game = function() {
        gameRunning = false;
        initBoard();
        spawnBlock(); // Spawn initial block
        score = 0;
        linesCleared = 0;
        level = 1; // Reset lines-based level
        difficultyLevel = 1; // Reset overall difficulty
        dropInterval = 1000; // Reset initial drop speed
        lastDropTime = 0;
        lastFrameTime = 0;
        lastDifficultyIncreaseTime = 0; // Will be set on game start

        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
        }
        removeInputListeners();

        if (backgroundMusicLoop) backgroundMusicLoop.stop();
        Tone.Transport.stop();
        Tone.Transport.cancel();

        drawGame(); // Draw initial state
    };

    /**
     * Starts the Day 4 game loop.
     */
    window.Day4Game.startGame = function() {
        if (gameRunning) return;
        gameRunning = true;
        addInputListeners();

        showModalMessageBoxRef('Day 4: Sound Block Harmony', `Your mission is to clear lines by arranging falling sound blocks!
            <br><br>Use <b>Arrow Left/Right</b> to move, <b>Arrow Down</b> for soft drop, <b>Arrow Up</b> to rotate, and <b>Spacebar</b> for hard drop.
            <br><br>When you clear a line, the blocks' sounds will create harmony. Aim for "Perfect Harmony" for bonus points!
            <br><br>Your superpower (${day4Player.superpower}) will give you an edge!`, () => {
            lastFrameTime = performance.now();
            lastDifficultyIncreaseTime = performance.now(); // Start difficulty timer
            animationFrameId = requestAnimationFrame(gameLoop);
            if (backgroundMusicLoop) {
                backgroundMusicLoop.mute = false;
                backgroundMusicLoop.start(0);
            }
            Tone.Transport.start();
        });
        console.log("Day 4 Sound Block Harmony Started.");
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

        if (backgroundMusicLoop) backgroundMusicLoop.stop();
        Tone.Transport.pause();

        showModalMessageBoxRef('Game Paused', 'The Harmony Game is on hold. Click OK to resume.', () => {
            lastFrameTime = performance.now(); // Reset lastFrameTime to prevent time jump
            animationFrameId = requestAnimationFrame(gameLoop);
            if (backgroundMusicLoop) backgroundMusicLoop.start(Tone.Transport.now());
            Tone.Transport.start();
        });
        console.log("Day 4 Sound Block Harmony Paused.");
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

        if (backgroundMusicLoop) backgroundMusicLoop.stop();
        Tone.Transport.stop();

        showModalMessageBoxRef('Restarting Day 4', 'Starting the Sound Block Harmony from the beginning.', () => {
            restartCurrentGameRef(); // Trigger restart of current game in main2.js
        });
    };

    /**
     * Handles window resize events to adjust canvas dimensions.
     */
    function onWindowResize() {
        if (canvas && canvas.parentElement) {
            canvas.width = canvas.parentElement.clientWidth;
            canvas.height = canvas.parentElement.clientHeight;
            drawGame(); // Redraw game elements after resize
        }
    }

})(); // End of IIFE

console.log("Day4-tetris.js script loaded.");
