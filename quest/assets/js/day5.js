/**
 * day5.js
 *
 * Contains the specific game logic for Day 5: The Office Maze (3D Game).
 * This module uses Three.js for rendering and Cannon.js for physics.
 *
 * @version 1.0.0
 * @date 2025-07-25
 * @author Gemini Assistant
 */

window.Day5Game = window.Day5Game || {};

(function() {
    // --- Global Game State ---
    let gameRunning = false;
    let animationFrameId = null;
    let canvas;
    let showMessageBoxRef;
    let updateGlobalScoreRef;
    let restartCurrentGameRef;
    let advanceToNextLevelRef;
    let playerSuperpower;

    // --- Three.js Variables ---
    let scene, camera, renderer;
    let player, playerBody;
    let lastFrameTime = 0;
    const playerSize = 1; // Player size in meters

    // --- Cannon.js Variables ---
    let world;
    const TIME_STEP = 1 / 60;

    // --- Game Config ---
    const config = {
        levelDuration: 60, // seconds
        gravity: -9.82,
        sleighSpeed: 5 // m/s
    };

    const obstacles = [];
    const harmonyNotes = [];

    // --- Audio ---
    let backgroundMusic;

    /**
     * Initializes the Three.js scene, camera, renderer, and Cannon.js world.
     */
    function setupGameEnvironment() {
        // Three.js setup
        scene = new THREE.Scene();
        scene.background = new THREE.Color(0x3A5E65); // EPOS Petrol Light
        scene.fog = new THREE.Fog(0x3A5E65, 10, 50);

        camera = new THREE.PerspectiveCamera(75, canvas.width / canvas.height, 0.1, 1000);
        camera.position.set(0, 10, 20);
        camera.lookAt(0, 0, 0);

        renderer = new THREE.WebGLRenderer({ canvas: canvas, antialias: true });
        renderer.setSize(canvas.width, canvas.height);
        renderer.setPixelRatio(window.devicePixelRatio);
        renderer.shadowMap.enabled = true;
        renderer.shadowMap.type = THREE.PCFSoftShadowMap;

        // Cannon.js physics world setup
        world = new CANNON.World();
        world.gravity.set(0, config.gravity, 0);
        world.broadphase = new CANNON.NaiveBroadphase();

        // Lighting
        const ambientLight = new THREE.AmbientLight(0xffffff, 0.5);
        scene.add(ambientLight);
        const directionalLight = new THREE.DirectionalLight(0xffffff, 1);
        directionalLight.position.set(5, 15, 8);
        directionalLight.castShadow = true;
        directionalLight.shadow.mapSize.width = 1024;
        directionalLight.shadow.mapSize.height = 1024;
        directionalLight.shadow.camera.near = 0.5;
        directionalLight.shadow.camera.far = 50;
        scene.add(directionalLight);
    }

    /**
     * Creates the ground plane.
     */
    function createGround() {
        // Three.js Mesh
        const groundGeometry = new THREE.PlaneGeometry(100, 100);
        const groundMaterial = new THREE.MeshStandardMaterial({ color: 0x808080 });
        const groundMesh = new THREE.Mesh(groundGeometry, groundMaterial);
        groundMesh.rotation.x = -Math.PI / 2;
        groundMesh.receiveShadow = true;
        scene.add(groundMesh);

        // Cannon.js Body
        const groundShape = new CANNON.Plane();
        const groundBody = new CANNON.Body({ mass: 0 }); // Mass 0 makes it static
        groundBody.addShape(groundShape);
        groundBody.quaternion.setFromAxisAngle(new CANNON.Vec3(1, 0, 0), -Math.PI / 2);
        world.addBody(groundBody);
    }

    /**
     * Creates the player character (sleigh).
     */
    function createPlayer() {
        // Three.js Mesh
        const playerGeometry = new THREE.BoxGeometry(playerSize, playerSize, playerSize);
        const playerMaterial = new THREE.MeshStandardMaterial({ color: 0xFF5549 });
        player = new THREE.Mesh(playerGeometry, playerMaterial);
        player.castShadow = true;
        scene.add(player);

        // Cannon.js Body
        const playerShape = new CANNON.Box(new CANNON.Vec3(playerSize / 2, playerSize / 2, playerSize / 2));
        playerBody = new CANNON.Body({ mass: 1, shape: playerShape });
        playerBody.position.set(0, playerSize / 2, 0);
        world.addBody(playerBody);
    }

    /**
     * Creates an obstacle.
     * @param {number} x X position.
     * @param {number} y Y position.
     * @param {number} z Z position.
     * @param {number} width Width of the obstacle.
     * @param {number} height Height of the obstacle.
     * @param {number} depth Depth of the obstacle.
     */
    function createObstacle(x, y, z, width, height, depth) {
        // Three.js Mesh
        const geometry = new THREE.BoxGeometry(width, height, depth);
        const material = new THREE.MeshStandardMaterial({ color: 0x00353D });
        const obstacleMesh = new THREE.Mesh(geometry, material);
        obstacleMesh.position.set(x, y + height / 2, z);
        obstacleMesh.castShadow = true;
        scene.add(obstacleMesh);

        // Cannon.js Body
        const shape = new CANNON.Box(new CANNON.Vec3(width / 2, height / 2, depth / 2));
        const body = new CANNON.Body({ mass: 0, shape: shape });
        body.position.set(x, y + height / 2, z);
        world.addBody(body);

        obstacles.push({ mesh: obstacleMesh, body: body });
    }

    /**
     * Creates a Harmony Note token.
     * @param {number} x X position.
     * @param {number} y Y position.
     * @param {number} z Z position.
     */
    function createHarmonyNote(x, y, z) {
        const radius = 0.5;
        const geometry = new THREE.IcosahedronGeometry(radius, 0);
        const material = new THREE.MeshStandardMaterial({
            color: 0x004785, // EPOS Blue
            emissive: 0x004785,
            emissiveIntensity: 0.5
        });
        const noteMesh = new THREE.Mesh(geometry, material);
        noteMesh.position.set(x, y, z);
        scene.add(noteMesh);

        const shape = new CANNON.Sphere(radius);
        const body = new CANNON.Body({ mass: 0, shape: shape });
        body.position.set(x, y, z);
        world.addBody(body);

        harmonyNotes.push({ mesh: noteMesh, body: body });
    }

    /**
     * Initializes the game for Day 5.
     * @param {object} playerConfig The player configuration from main.js.
     */
    function initGame(playerConfig) {
        playerSuperpower = playerConfig.superpower;
        setupGameEnvironment();
        createGround();
        createPlayer();

        // Create a simple maze/office layout
        createObstacle(-5, 0, -10, 2, 5, 20);
        createObstacle(5, 0, 10, 2, 5, 20);
        createObstacle(0, 0, -5, 10, 5, 2);
        createObstacle(0, 0, 15, 10, 5, 2);

        // Place harmony notes in the maze
        createHarmonyNote(3, 1, -8);
        createHarmonyNote(-3, 1, 8);
        createHarmonyNote(0, 1, -15);
        createHarmonyNote(0, 1, 12);
        createHarmonyNote(-8, 1, -1);
    }

    /**
     * Handles keyboard input for moving the player.
     * @param {KeyboardEvent} event The keyboard event.
     */
    function handleKeyDown(event) {
        const vel = playerBody.velocity;
        switch (event.key) {
            case 'ArrowUp':
                playerBody.velocity.z = -config.sleighSpeed;
                break;
            case 'ArrowDown':
                playerBody.velocity.z = config.sleighSpeed;
                break;
            case 'ArrowLeft':
                playerBody.velocity.x = -config.sleighSpeed;
                break;
            case 'ArrowRight':
                playerBody.velocity.x = config.sleighSpeed;
                break;
        }
    }

    /**
     * Handles keyboard input for stopping the player.
     * @param {KeyboardEvent} event The keyboard event.
     */
    function handleKeyUp(event) {
        const vel = playerBody.velocity;
        switch (event.key) {
            case 'ArrowUp':
            case 'ArrowDown':
                playerBody.velocity.z = 0;
                break;
            case 'ArrowLeft':
            case 'ArrowRight':
                playerBody.velocity.x = 0;
                break;
        }
    }

    /**
     * Main game loop.
     */
    function gameLoop(timestamp) {
        if (!gameRunning) return;

        const delta = (timestamp - lastFrameTime) / 1000;
        lastFrameTime = timestamp;

        // Update physics
        world.step(TIME_STEP, delta);

        // Update Three.js meshes from Cannon.js bodies
        player.position.copy(playerBody.position);
        player.quaternion.copy(playerBody.quaternion);

        // Check for collisions with harmony notes
        for (let i = harmonyNotes.length - 1; i >= 0; i--) {
            const note = harmonyNotes[i];
            const distance = player.position.distanceTo(note.mesh.position);
            if (distance < 1.0) { // Check if player is close enough
                updateGlobalScoreRef(100);
                scene.remove(note.mesh);
                world.remove(note.body);
                harmonyNotes.splice(i, 1);

                // Play a collection sound
                new Tone.Synth().toDestination().triggerAttackRelease("C5", "8n");
            }
        }

        // Check for level completion
        if (harmonyNotes.length === 0) {
            endGame(true);
            return;
        }

        // Update camera to follow the player from a fixed angle
        camera.position.x = player.position.x;
        camera.position.z = player.position.z + 10;
        camera.position.y = player.position.y + 10;
        camera.lookAt(player.position.x, player.position.y, player.position.z);


        renderer.render(scene, camera);
        animationFrameId = requestAnimationFrame(gameLoop);
    }

    /**
     * Ends the game and shows a win/loss message.
     * @param {boolean} didWin True if the player won, false otherwise.
     */
    function endGame(didWin) {
        gameRunning = false;
        if (animationFrameId) cancelAnimationFrame(animationFrameId);
        removeInputListeners();
        
        let title, message;
        if (didWin) {
            title = 'Harmony Achieved!';
            message = 'You collected all the Harmony Notes! Day 5 is complete!';
            advanceToNextLevelRef();
        } else {
            title = 'Quest Failed';
            message = 'You got lost in the office maze. Try again!';
            restartCurrentGameRef();
        }

        showMessageBoxRef(title, message, null, 0, true);
    }

    /**
     * Adds event listeners for game input.
     */
    function addInputListeners() {
        document.addEventListener('keydown', handleKeyDown);
        document.addEventListener('keyup', handleKeyUp);
    }

    /**
     * Removes event listeners for game input.
     */
    function removeInputListeners() {
        document.removeEventListener('keydown', handleKeyDown);
        document.removeEventListener('keyup', handleKeyUp);
    }

    /**
     * Initializes Tone.js for the game.
     */
    function setupAudio() {
        backgroundMusic = new Tone.Synth().toDestination();
        const musicLoop = new Tone.Loop(time => {
            backgroundMusic.triggerAttackRelease("C4", "8n", time);
            backgroundMusic.triggerAttackRelease("E4", "8n", time + Tone.Time("8n"));
            backgroundMusic.triggerAttackRelease("G4", "8n", time + Tone.Time("4n"));
        }, "1m");
        Tone.Transport.start();
        musicLoop.start(0);
    }

    // --- Exposed Functions for main.js ---

    /**
     * Initializes the Day 5 game module.
     */
    window.Day5Game.init = function(canvasRef, showMessageBox, updateGlobalScore, restartGame, advanceToNextLevel, superpower) {
        canvas = canvasRef;
        showMessageBoxRef = showMessageBox;
        updateGlobalScoreRef = updateGlobalScore;
        restartCurrentGameRef = restartGame;
        advanceToNextLevelRef = advanceToNextLevel;
        playerSuperpower = superpower;
    };

    /**
     * Starts the Day 5 game.
     */
    window.Day5Game.startGame = function() {
        gameRunning = true;
        lastFrameTime = performance.now();
        initGame();
        addInputListeners();
        gameLoop();
        // setupAudio();
        showMessageBoxRef('Day 5: Office Maze', 'Navigate the virtual office to find all the Harmony Notes!', null, 0, true);
        console.log("Day 5 Office Maze started.");
    };

    /**
     * Pauses the Day 5 game.
     */
    window.Day5Game.pauseGame = function() {
        gameRunning = false;
        if (animationFrameId) cancelAnimationFrame(animationFrameId);
        removeInputListeners();
        showMessageBoxRef('Game Paused', 'Your journey is on hold.', () => {
            lastFrameTime = performance.now();
            gameRunning = true;
            addInputListeners();
            gameLoop();
        }, 0, true);
        console.log("Day 5 Office Maze paused.");
    };

    /**
     * Resets the Day 5 game to its initial state.
     */
    window.Day5Game.resetGame = function() {
        gameRunning = false;
        if (animationFrameId) cancelAnimationFrame(animationFrameId);
        removeInputListeners();
        // Dispose of Three.js and Cannon.js objects to prevent memory leaks
        while(scene.children.length > 0){
            scene.remove(scene.children[0]);
        }
        world.bodies.forEach(body => world.remove(body));
        obstacles.length = 0;
        harmonyNotes.length = 0;
        console.log("Day 5 game reset.");
    };

    /**
     * Handles window resize events to adjust canvas dimensions and redraw the game.
     */
    window.Day5Game.onWindowResize = function() {
        if (canvas && renderer) {
            canvas.width = canvas.parentElement.clientWidth;
            canvas.height = canvas.parentElement.clientHeight;
            renderer.setSize(canvas.width, canvas.height);
            camera.aspect = canvas.width / canvas.height;
            camera.updateProjectionMatrix();
        }
    };

    console.log("Day5.js script loaded.");

})();
