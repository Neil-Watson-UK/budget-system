/**
 * quest-map.js
 *
 * This file contains the Three.js logic for the 3D Holiday Harmony Quest Map.
 * It renders the 15 days as interactive nodes, representing different locations
 * and allows players to select a day to start a challenge.
 *
 * @version 1.0.26
 * @date 2025-07-17
 * @author Gemini Assistant
 */

// Define a global object to hold QuestMap functions immediately
window.QuestMap = window.QuestMap || {};
console.log("window.QuestMap namespace defined."); // Debugging log

// Three.js components - now accessed globally from window object
// No import statements for THREE or OrbitControls here, as they are loaded globally in index.html

// --- Three.js Global Variables ---
// THREE and OrbitControls are now expected to be globally available
let scene, camera, renderer, controls; // controls will be new window.OrbitControls
let raycaster;
let mouse;
let INTERSECTED; // To store the currently hovered object
let dayNodes = []; // Array to hold all 3D day objects (now representing rooms)
let lines = []; // Array to hold the lines connecting nodes

// --- Game State References (passed from main2.js) ---
let currentPlayer = {};
let currentLevel = 1;
let selectDayCallback = null; // Callback to main2.js when a day is selected
let showMessageBoxRef; // Reference to main2.js's showMessageBox function
let questMapCanvasRef; // Renamed to avoid conflict and clarify its role as a reference

// --- Configuration ---
const NUM_DAYS = 15;
const ROOM_SIZE = 12; // Increased size of each square room (width/depth)
const ROOM_HEIGHT = 8; // Increased height of each room
const ROOM_SPACING_X = 22; // Increased distance between room centers along X-axis
const ROOM_SPACING_Y_FLUCTUATION = 7; // Max vertical wiggle for rooms
const ROOM_SPACING_Z_FLUCTUATION = 12; // Max depth wiggle for rooms
const PATH_RADIUS = 0.8; // Thicker path cylinder to connect rooms

// Colors (matching EPOS theme)
const COLORS = {
    eposBlack: 0x131313,
    eposPetrol: 0x002B32,
    eposMint: 0xA7D9D3,
    goldAccent: 0xFFD700,
    festiveRed: 0xB22222,
    nodeLocked: 0x555555, // Slightly darker grey for locked nodes
    nodeUnlocked: 0x00a399, // EPOS Teal for unlocked nodes
    nodeCompleted: 0x004D55, // Darker teal for completed nodes
    nodeCurrent: 0xFFD700, // Gold for current day
    pathColor: 0x004D55, // Dark petrol for paths
    hoverColor: 0xA7D9D3, // Mint for hover
    roomFloor: 0x3A5E65, // A slightly lighter petrol for room floors (more distinct)
    roomWall: 0x4A7E85, // Another shade for walls (more distinct)
    detailColor: 0xCCCCCC, // Light grey for doors/windows
    groundColor: 0x001A1F, // Very dark petrol for the ground
    furnitureColor: 0x5A5A5A, // Dark grey for furniture
    plantColor: 0x00AA00, // Green for plants
    potColor: 0x8B4513, // Brown for pot
};

// Map locations/themes for each day (example, can be expanded)
const DAY_LOCATIONS = [
    { name: "Day 1 - Sound Principles - Servicing the Sleigh", type: "office" },
    { name: "Day 2 - Placeholder", type: "home" },
    { name: "Day 3 - Placeholder", type: "airport" },
    { name: "Day 4 - Placeholder", type: "road" },
    { name: "Day 5 - Placeholder", type: "home" },
    { name: "Day 6 - Placeholder", type: "office" },
    { name: "Day 7 - Placeholder", type: "road" },
    { name: "Day 8 - Placeholder", type: "airport" },
    { name: "Day 9 - Placeholder", type: "home" },
    { name: "Day 10 - Placeholder", type: "office" },
    { name: "Day 11 - Placeholder", type: "road" },
    { name: "Day 12 - Placeholder", type: "airport" },
    { name: "Day 13 - Placeholder", type: "road" },
    { name: "Day 14 - Placeholder", type: "office" },
    { name: "Day 15 - Holiday Party!", type: "celebration" }
];

// --- Scene Initialization ---
async function setupScene() {
    console.log("setupScene called."); // Debugging log

    const MAX_RETRIES = 50; // Max 5 seconds of retrying (50 * 100ms)
    let retryCount = 0;

    // Robust check: Poll until THREE and OrbitControls are available
    const checkDependencies = () => {
        console.log(`Checking dependencies: window.THREE = ${typeof window.THREE}, window.THREE.OrbitControls = ${typeof window.THREE.OrbitControls}`);
        // If window.THREE exists but OrbitControls is not directly on it, check for a global OrbitControls
        if (typeof window.THREE !== 'undefined' && typeof window.THREE.OrbitControls === 'undefined') {
            if (typeof OrbitControls !== 'undefined' && typeof OrbitControls === 'function') {
                console.warn("Global OrbitControls found, manually assigning to window.THREE.OrbitControls.");
                window.THREE.OrbitControls = OrbitControls; // Manual assignment
            }
        }

        if (typeof window.THREE === 'undefined' || typeof window.THREE.OrbitControls === 'undefined') {
            console.warn("THREE.js or OrbitControls not yet available in setupScene. Retrying...");
            return false;
        }
        return true;
    };

    // Wait for dependencies to be ready
    while (!checkDependencies() && retryCount < MAX_RETRIES) {
        await new Promise(resolve => setTimeout(resolve, 100)); // Wait 100ms before retrying
        retryCount++;
    }

    // Final check after retries
    if (!checkDependencies()) {
        const errorMessage = "Critical: THREE.js OrbitControls is still undefined after multiple retries. Please ensure your index.html includes the correct script tags for both three.min.js and OrbitControls.js, and that they are compatible versions (e.g., from the same Three.js release, like r128). OrbitControls.js should be loaded AFTER three.min.js.";
        console.error(errorMessage);
        if (showMessageBoxRef) {
            showMessageBoxRef("Error Loading Map", errorMessage, null, 0);
        }
        return; // Stop scene setup
    }
    console.log("THREE.js and OrbitControls are now available.");

    if (!questMapCanvasRef) {
        console.error("Quest map canvas element is not provided. Cannot set up scene.");
        return;
    }

    // Scene
    scene = new window.THREE.Scene();
    scene.background = new window.THREE.Color(COLORS.eposPetrol); // Dark petrol background

    // Camera - Adjusted for Isometric-like view (looking from top-left towards path)
    camera = new window.THREE.PerspectiveCamera(45, questMapCanvasRef.clientWidth / questMapCanvasRef.clientHeight, 0.1, 1000); // Lower FOV for less perspective distortion
    // Position camera to view Day 1 from the left, looking right along the X-axis
    camera.position.set(-25, 25, 25); // Slightly further back and higher for better overview
    camera.lookAt(0, 0, 0); // Look towards the origin (where Day 1 starts)

    // Renderer
    renderer = new window.THREE.WebGLRenderer({ canvas: questMapCanvasRef, antialias: true });
    renderer.setSize(questMapCanvasRef.clientWidth, questMapCanvasRef.clientHeight);
    renderer.setPixelRatio(window.devicePixelRatio);

    // Controls
    controls = new window.THREE.OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true; // Smooth camera movement
    controls.dampingFactor = 0.08; // Slightly increased damping for smoother feel
    controls.screenSpacePanning = false;
    controls.maxPolarAngle = Math.PI * 0.55; // Allow slightly more vertical rotation
    controls.minPolarAngle = Math.PI * 0.20; // Prevent going too flat
    controls.minDistance = 15; // Minimum zoom (further back)
    controls.maxDistance = 80; // Maximum zoom (more distant)
    // Target the origin, as Day 1 is at (0,0,0)
    controls.target.set(0, 0, 0);

    // Lighting
    const ambientLight = new window.THREE.AmbientLight(0x404040, 2); // Soft ambient light
    scene.add(ambientLight);
    const directionalLight = new window.THREE.DirectionalLight(0xffffff, 1.5); // Brighter directional light
    directionalLight.position.set(10, 20, 15); // Position it to cast light down the path
    scene.add(directionalLight);
    const pointLight = new window.THREE.PointLight(0xffffe0, 0.5, 100); // Warm point light
    pointLight.position.set(0, 10, 0);
    scene.add(pointLight);

    // Raycaster for interaction
    raycaster = new window.THREE.Raycaster();
    mouse = new window.THREE.Vector2();

    // Event Listeners
    window.addEventListener('resize', onWindowResize);
    // Use questMapCanvasRef for event listeners
    questMapCanvasRef.addEventListener('mousemove', onMouseMove);
    questMapCanvasRef.addEventListener('click', onClick);
    questMapCanvasRef.addEventListener('touchmove', onTouchMove); // For mobile hover
    questMapCanvasRef.addEventListener('touchend', onTouchEnd); // For mobile click

    // Start the animation loop here, after controls and renderer are initialized
    animate();
    console.log("Three.js scene setup complete and animation started."); // Debugging log
}

/**
 * Creates a single isometric room with basic details.
 * @param {number} day The day number.
 * @param {number} x X position of the room.
 * @param {number} y Y position of the room.
 * @param {number} z Z position of the room.
 * @param {object} materials Object containing floor, wall, and detail materials.
 * @returns {THREE.Group} A group containing all room meshes.
 */
function createRoom(day, x, y, z, materials) {
    const roomGroup = new window.THREE.Group();
    roomGroup.position.set(x, y, z);
    roomGroup.userData = { day: day, type: 'dayNode', location: DAY_LOCATIONS[day - 1].name };

    const wallThickness = 0.5;

    // Floor
    const floorGeometry = new window.THREE.BoxGeometry(ROOM_SIZE, 0.5, ROOM_SIZE);
    const floorMesh = new window.THREE.Mesh(floorGeometry, materials.floor);
    floorMesh.position.set(0, -ROOM_HEIGHT / 2, 0);
    roomGroup.add(floorMesh);

    // Walls (back, right, left for isometric view)
    const backWallGeometry = new window.THREE.BoxGeometry(ROOM_SIZE, ROOM_HEIGHT, wallThickness);
    const backWallMesh = new window.THREE.Mesh(backWallGeometry, materials.wall);
    backWallMesh.position.set(0, 0, -ROOM_SIZE / 2 + wallThickness / 2);
    roomGroup.add(backWallMesh);

    const rightWallGeometry = new window.THREE.BoxGeometry(wallThickness, ROOM_HEIGHT, ROOM_SIZE);
    const rightWallMesh = new window.THREE.Mesh(rightWallGeometry, materials.wall);
    rightWallMesh.position.set(ROOM_SIZE / 2 - wallThickness / 2, 0, 0);
    roomGroup.add(rightWallMesh);

    const leftWallGeometry = new window.THREE.BoxGeometry(wallThickness, ROOM_HEIGHT, ROOM_SIZE);
    const leftWallMesh = new window.THREE.Mesh(leftWallGeometry, materials.wall);
    leftWallMesh.position.set(-ROOM_SIZE / 2 + wallThickness / 2, 0, 0);
    roomGroup.add(leftWallMesh);

    // --- Add basic room details (Doors & Windows) ---
    // Simple rectangular door on the back wall
    const doorGeometry = new window.THREE.BoxGeometry(ROOM_SIZE * 0.2, ROOM_HEIGHT * 0.6, wallThickness + 0.01);
    const doorMesh = new window.THREE.Mesh(doorGeometry, materials.detail);
    doorMesh.position.set(0, -ROOM_HEIGHT * 0.2, -ROOM_SIZE / 2 + wallThickness / 2);
    roomGroup.add(doorMesh);

    // Window on the right wall
    const windowGeometry = new window.THREE.BoxGeometry(wallThickness + 0.01, ROOM_HEIGHT * 0.3, ROOM_SIZE * 0.4);
    const windowMesh = new window.THREE.Mesh(windowGeometry, materials.detail);
    windowMesh.position.set(ROOM_SIZE / 2 - wallThickness / 2, ROOM_HEIGHT * 0.1, 0);
    roomGroup.add(windowMesh);

    // --- Add simple furniture ---
    // Table
    const tableTopGeometry = new window.THREE.BoxGeometry(ROOM_SIZE * 0.4, 0.3, ROOM_SIZE * 0.3);
    const tableTopMesh = new window.THREE.Mesh(tableTopGeometry, new window.THREE.MeshStandardMaterial({ color: COLORS.furnitureColor }));
    tableTopMesh.position.set(-ROOM_SIZE * 0.2, -ROOM_HEIGHT / 2 + 0.3 + 0.15, ROOM_SIZE * 0.1); // Adjusted Y for floor
    roomGroup.add(tableTopMesh);

    const tableLegGeometry = new window.THREE.BoxGeometry(0.3, ROOM_HEIGHT * 0.3, 0.3);
    const tableLegMesh = new window.THREE.Mesh(tableLegGeometry, new window.THREE.MeshStandardMaterial({ color: COLORS.furnitureColor }));
    tableLegMesh.position.set(-ROOM_SIZE * 0.2, -ROOM_HEIGHT / 2 + (ROOM_HEIGHT * 0.3) / 2, ROOM_SIZE * 0.1); // Adjusted Y for floor
    roomGroup.add(tableLegMesh);

    // Simple Plant
    const potGeometry = new window.THREE.CylinderGeometry(0.5, 0.5, 1, 16);
    const potMesh = new window.THREE.Mesh(potGeometry, new window.THREE.MeshStandardMaterial({ color: COLORS.potColor }));
    potMesh.position.set(ROOM_SIZE * 0.3, -ROOM_HEIGHT / 2 + 0.5, -ROOM_SIZE * 0.3); // Adjusted Y for floor
    roomGroup.add(potMesh);

    const foliageGeometry = new window.THREE.SphereGeometry(0.8, 16, 16);
    const foliageMesh = new window.THREE.Mesh(foliageGeometry, new window.THREE.MeshStandardMaterial({ color: COLORS.plantColor }));
    foliageMesh.position.set(ROOM_SIZE * 0.3, -ROOM_HEIGHT / 2 + 0.5 + 0.8, -ROOM_SIZE * 0.3); // Above the pot
    roomGroup.add(foliageMesh);


    // Add a "clickable" invisible plane for raycasting on the room
    const clickablePlaneGeometry = new window.THREE.PlaneGeometry(ROOM_SIZE, ROOM_HEIGHT);
    const clickablePlaneMaterial = new window.THREE.MeshBasicMaterial({ visible: false }); // Invisible
    const clickablePlane = new window.THREE.Mesh(clickablePlaneGeometry, clickablePlaneMaterial);
    clickablePlane.position.set(0, 0, 0); // Center of the room
    clickablePlane.userData = { day: day, type: 'dayNode', location: DAY_LOCATIONS[day - 1].name }; // Same user data as roomGroup
    roomGroup.add(clickablePlane); // Add to group, so its position is relative

    return roomGroup;
}


// --- Map Creation ---
function createMapNodes() {
    console.log("createMapNodes called."); // Debugging log
    // Check if scene is initialized before adding objects
    if (!scene) {
        console.error("Scene is not initialized. Cannot create map nodes.");
        return;
    }

    dayNodes = []; // Clear previous nodes
    lines = []; // Clear previous lines

    const pathMaterial = new window.THREE.MeshStandardMaterial({ color: COLORS.pathColor, transparent: true, opacity: 0.8, roughness: 0.5, metalness: 0.1 });
    const materials = {
        floor: new window.THREE.MeshStandardMaterial({ color: COLORS.roomFloor, roughness: 0.8, metalness: 0.1 }),
        wall: new window.THREE.MeshStandardMaterial({ color: COLORS.roomWall, roughness: 0.7, metalness: 0.1 }),
        detail: new window.THREE.MeshStandardMaterial({ color: COLORS.detailColor, roughness: 0.6, metalness: 0.1 }),
    };

    // --- Ground Plane ---
    const groundGeometry = new window.THREE.PlaneGeometry(ROOM_SPACING_X * NUM_DAYS * 1.5, ROOM_SPACING_Z_FLUCTUATION * 3); // Larger ground plane
    const groundMaterial = new window.THREE.MeshStandardMaterial({ color: COLORS.groundColor, roughness: 0.9, metalness: 0.0 });
    const ground = new window.THREE.Mesh(groundGeometry, groundMaterial);
    ground.rotation.x = -Math.PI / 2; // Rotate to be horizontal
    ground.position.y = -ROOM_HEIGHT / 2 - 1; // Position below the rooms
    ground.position.x = (NUM_DAYS / 2 - 0.5) * ROOM_SPACING_X; // Center along X
    ground.position.z = 0; // Center along Z
    scene.add(ground);
    console.log("Ground plane added.");


    for (let i = 0; i < NUM_DAYS; i++) {
        const day = i + 1;
        // Position rooms in a winding path along the X-axis (left-to-right progression)
        const x = i * ROOM_SPACING_X; // Primary axis for progression
        const y = Math.sin(i * 0.7) * ROOM_SPACING_Y_FLUCTUATION; // Vertical wiggle
        const z = Math.cos(i * 0.5) * ROOM_SPACING_Z_FLUCTUATION; // Depth wiggle

        const roomGroup = createRoom(day, x, y, z, materials);
        scene.add(roomGroup);

        // Find the clickable plane within the roomGroup to add to dayNodes
        const clickablePlane = roomGroup.children.find(child => child.userData.type === 'dayNode');
        if (clickablePlane) {
            dayNodes.push(clickablePlane);
        }

        // Add day number and challenge name text
        const dayTextSprite = createTextSprite(`Day ${day}`, { fontSize: 1.0, color: '#ffffff' }); // Increased font size
        dayTextSprite.position.set(x, y + ROOM_HEIGHT / 2 + 2.5, z); // Position higher above the room
        scene.add(dayTextSprite);
        roomGroup.userData.dayTextSprite = dayTextSprite; // Store reference

        const challengeNameSprite = createTextSprite(DAY_LOCATIONS[i].name, { fontSize: 0.6, color: '#ffffff' }); // Increased font size
        challengeNameSprite.position.set(x, y + ROOM_HEIGHT / 2 + 1.5, z); // Position below day number, with more space
        scene.add(challengeNameSprite);
        roomGroup.userData.challengeNameSprite = challengeNameSprite; // Store reference

        // Connect rooms with lines (cylinders)
        if (i > 0) {
            const prevRoomCenter = new window.THREE.Vector3(
                dayNodes[i - 1].parent.position.x,
                dayNodes[i - 1].parent.position.y,
                dayNodes[i - 1].parent.position.z
            );
            const currentRoomCenter = new window.THREE.Vector3(x, y, z);

            const direction = new window.THREE.Vector3().subVectors(currentRoomCenter, prevRoomCenter);
            const length = direction.length();
            const cylinderGeometry = new window.THREE.CylinderGeometry(PATH_RADIUS, PATH_RADIUS, length, 16);
            const cylinder = new window.THREE.Mesh(cylinderGeometry, pathMaterial);

            cylinder.position.copy(prevRoomCenter).add(direction.clone().multiplyScalar(0.5));
            // Crucial change: align cylinder with new direction along X-axis
            cylinder.quaternion.setFromUnitVectors(new window.THREE.Vector3(0, 1, 0), direction.normalize()); // Corrected axis for alignment
            scene.add(cylinder);
            lines.push(cylinder);
        }
    }
    console.log("Map nodes (rooms) created."); // Debugging log
}

/**
 * Creates a 2D text sprite for Three.js.
 * @param {string} message The text content.
 * @param {object} parameters Parameters for the sprite.
 * @returns {THREE.Sprite} The created sprite.
 */
function createTextSprite(message, parameters) {
    if (parameters === undefined) parameters = {};
    const fontface = parameters.hasOwnProperty("fontface") ? parameters["fontface"] : "Inter"; // Use Inter font
    const fontsize = parameters.hasOwnProperty("fontsize") ? parameters["fontsize"] : 18;
    const borderThickness = parameters.hasOwnProperty("borderThickness") ? parameters["borderThickness"] : 0;
    const borderColor = parameters.hasOwnProperty("borderColor") ? parameters["borderColor"] : { r: 0, g: 0, b: 0, a: 1.0 };
    const backgroundColor = parameters.hasOwnProperty("backgroundColor") ? parameters["backgroundColor"] : { r: 0, g: 0, b: 0, a: 0.0 };
    const color = parameters.hasOwnProperty("color") ? parameters["color"] : '#ffffff';

    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');
    context.font = "Bold " + fontsize + "px " + fontface;
    const metrics = context.measureText(message);
    const textWidth = metrics.width;

    // Ensure canvas size is sufficient for text
    canvas.width = textWidth + borderThickness * 2;
    canvas.height = fontsize + borderThickness * 2;

    // Redraw context after resizing canvas
    context.font = "Bold " + fontsize + "px " + fontface;
    context.textAlign = "center";
    context.textBaseline = "middle";
    context.fillStyle = "rgba(" + backgroundColor.r + "," + backgroundColor.g + "," + backgroundColor.b + "," + backgroundColor.a + ")";
    context.strokeStyle = "rgba(" + borderColor.r + "," + borderColor.g + "," + borderColor.b + "," + borderColor.a + ")";
    context.lineWidth = borderThickness;
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.strokeRect(0, 0, canvas.width, canvas.height);
    context.fillStyle = color;
    context.fillText(message, canvas.width / 2, canvas.height / 2);

    const texture = new window.THREE.CanvasTexture(canvas);
    texture.needsUpdate = true;

    const spriteMaterial = new window.THREE.SpriteMaterial({ map: texture, transparent: true }); // Ensure transparency
    const sprite = new window.THREE.Sprite(spriteMaterial);
    // Scale sprite based on desired world size
    sprite.scale.set(canvas.width * 0.1, canvas.height * 0.1, 1); // Adjusted scale for better visibility
    return sprite;
}


// --- Rendering Loop ---
function animate() {
    requestAnimationFrame(animate);
    // Ensure controls and renderer are defined before calling their methods
    if (controls && renderer) {
        controls.update();
        renderer.render(scene, camera);
    } else {
        // This warning should now only appear if setupScene itself failed, not due to a race condition
        console.warn("Animation loop skipped: controls or renderer not initialized.");
    }
}

// --- Event Handlers ---
function onWindowResize() {
    // Use questMapCanvasRef for resize events
    if (questMapCanvasRef && camera && renderer) {
        camera.aspect = questMapCanvasRef.clientWidth / questMapCanvasRef.clientHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(questMapCanvasRef.clientWidth, questMapCanvasRef.clientHeight);
    }
}

function onMouseMove(event) {
    // Use questMapCanvasRef for mouse events
    if (!raycaster || !camera || !questMapCanvasRef) return; // Ensure Three.js components and canvas are initialized

    const rect = questMapCanvasRef.getBoundingClientRect();
    mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
    mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;

    raycaster.setFromCamera(mouse, camera);

    // Intersect with the clickable planes (dayNodes array)
    const intersects = raycaster.intersectObjects(dayNodes);

    if (intersects.length > 0) {
        if (INTERSECTED != intersects[0].object) {
            if (INTERSECTED) {
                // Reset previous INTERSECTED's parent group's material emissive color
                if (INTERSECTED.parent && INTERSECTED.parent.children) {
                    INTERSECTED.parent.children.forEach(child => {
                        if (child.material && child.material.emissive) {
                            child.material.emissive.setHex(0x000000); // No glow
                        }
                        // Restore original color based on state for the floor and walls
                        if (child.geometry && child.geometry.type === 'BoxGeometry') {
                            const day = INTERSECTED.userData.day;
                            if (day < currentLevel) {
                                child.material.color.setHex(COLORS.nodeCompleted);
                            } else if (day === currentLevel) {
                                child.material.color.setHex(COLORS.nodeCurrent);
                            } else {
                                child.material.color.setHex(COLORS.nodeLocked);
                            }
                        }
                    });
                }
            }
            INTERSECTED = intersects[0].object; // The clickable plane
            // Apply hover effect to the parent group's visible meshes (floor, walls, details)
            if (INTERSECTED.parent && INTERSECTED.parent.children) {
                INTERSECTED.parent.children.forEach(child => {
                    if (child.material && child.material.emissive && child.geometry.type !== 'PlaneGeometry') { // Exclude the invisible plane
                        child.material.color.setHex(COLORS.hoverColor);
                        child.material.emissive.setHex(0x333333); // Subtle glow on hover
                    }
                });
            }
            questMapCanvasRef.style.cursor = 'pointer';
        }
    } else {
        if (INTERSECTED) {
            // Reset previous INTERSECTED's parent group's material emissive color
            if (INTERSECTED.parent && INTERSECTED.parent.children) {
                INTERSECTED.parent.children.forEach(child => {
                    if (child.material && child.material.emissive) {
                        child.material.emissive.setHex(0x000000); // No glow
                    }
                    // Restore original color based on state for the floor and walls
                    if (child.geometry && child.geometry.type === 'BoxGeometry') {
                        const day = INTERSECTED.userData.day;
                        if (day < currentLevel) {
                            child.material.color.setHex(COLORS.nodeCompleted);
                        } else if (day === currentLevel) {
                            child.material.color.setHex(COLORS.nodeCurrent);
                        } else {
                            child.material.color.setHex(COLORS.nodeLocked);
                        }
                    }
                });
            }
        }
        INTERSECTED = null;
        questMapCanvasRef.style.cursor = 'auto';
    }
}

function onClick(event) {
    if (INTERSECTED && INTERSECTED.userData.type === 'dayNode') {
        const selectedDay = INTERSECTED.userData.day;
        if (selectedDay <= currentLevel) {
            // Get the target position of the room
            const targetRoomPosition = INTERSECTED.parent.position;

            // Calculate a camera position that maintains the isometric angle relative to the target
            // This is a simplified approach; a more robust solution might involve a fixed offset
            // from the target or a dedicated camera animation library (like TWEEN.js).
            const cameraOffset = new window.THREE.Vector3(-25, 25, 25); // Maintain original camera offset
            const newCameraPosition = new window.THREE.Vector3().addVectors(targetRoomPosition, cameraOffset);

            // Simple linear interpolation for camera movement
            const animationDuration = 500; // milliseconds
            const startTime = performance.now();
            const initialCameraPosition = camera.position.clone();
            const initialControlsTarget = controls.target.clone();

            function animateCamera() {
                const currentTime = performance.now();
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / animationDuration, 1);

                // Interpolate camera position
                camera.position.lerpVectors(initialCameraPosition, newCameraPosition, progress);
                // Interpolate controls target
                controls.target.lerpVectors(initialControlsTarget, targetRoomPosition, progress);

                controls.update(); // Update controls after changing position/target

                if (progress < 1) {
                    requestAnimationFrame(animateCamera);
                } else {
                    // Animation complete, now trigger the day selection callback
                    if (selectDayCallback) {
                        selectDayCallback(selectedDay);
                    }
                }
            }
            animateCamera(); // Start the animation
        } else {
            if (showMessageBoxRef) {
                showMessageBoxRef('Day Locked', `Day ${selectedDay} is currently locked. Complete previous challenges to unlock!`, 2000);
            }
        }
    }
}

function onTouchMove(event) {
    if (event.touches.length > 0) {
        onMouseMove(event.touches[0]);
    }
}

function onTouchEnd(event) {
    if (INTERSECTED && INTERSECTED.userData.type === 'dayNode') {
        onClick(event);
    }
    if (INTERSECTED) {
        // Reset emissive color on touch end for the parent group's meshes
        if (INTERSECTED.parent && INTERSECTED.parent.children) {
            INTERSECTED.parent.children.forEach(child => {
                if (child.material && child.material.emissive) {
                    child.material.emissive.setHex(0x000000); // No glow
                }
                // Restore original color based on state for the floor and walls
                if (child.geometry && child.geometry.type === 'BoxGeometry') {
                    const day = INTERSECTED.userData.day;
                    if (day < currentLevel) {
                        child.material.color.setHex(COLORS.nodeCompleted);
                    } else if (day === currentLevel) {
                        child.material.color.setHex(COLORS.nodeCurrent);
                    } else {
                        child.material.color.setHex(COLORS.nodeLocked);
                    }
                }
            });
        }
        INTERSECTED = null;
        questMapCanvasRef.style.cursor = 'auto';
    }
}


// --- Exported Functions for main2.js (now exposed globally) ---
/**
 * Initializes the 3D quest map.
 * @param {HTMLCanvasElement} canvasElement The canvas DOM element for Three.js.
 * @param {object} playerInfo Current player object from main2.js.
 * @param {number} levelInfo Current game level from main2.js.
 * @param {function} callback Function to call in main2.js when a day is selected.
 * @param {function} messageBoxFunction Reference to main2.js's showMessageBox.
 */
window.QuestMap.initQuestMap = async function(canvasElement, playerInfo, levelInfo, callback, messageBoxFunction) {
    console.log("QuestMap.initQuestMap called."); // Debugging log
    questMapCanvasRef = canvasElement; // Assign the passed canvas element to the local reference
    currentPlayer = playerInfo;
    currentLevel = levelInfo;
    selectDayCallback = callback;
    showMessageBoxRef = messageBoxFunction;

    if (!scene) { // Only set up scene once
        await setupScene(); // Now animate() is called within setupScene()
        createMapNodes();
    }
    window.QuestMap.updateQuestMapProgress(currentLevel); // Call through global object
};

/**
 * Shows the 3D quest map.
 */
window.QuestMap.showQuestMap = function() {
    console.log("QuestMap.showQuestMap called."); // Debugging log
    if (questMapCanvasRef) { // Use questMapCanvasRef
        questMapCanvasRef.style.display = 'block';
        onWindowResize();
        if (controls) controls.update(); // Ensure controls are initialized
    }
};

/**
 * Hides the 3D quest map.
 */
window.QuestMap.hideQuestMap = function() {
    console.log("QuestMap.hideQuestMap called."); // Debugging log
    if (questMapCanvasRef) { // Use questMapCanvasRef
        questMapCanvasRef.style.display = 'none';
    }
};

/**
 * Updates the visual state of the quest map nodes (locked, unlocked, current, completed).
 * @param {number} newLevel The current level of the player.
 */
window.QuestMap.updateQuestMapProgress = function(newLevel) {
    console.log(`QuestMap.updateQuestMapProgress called with level: ${newLevel}`); // Debugging log
    currentLevel = newLevel;
    dayNodes.forEach(clickablePlane => { // dayNodes now contains clickable planes
        const day = clickablePlane.userData.day;
        const roomGroup = clickablePlane.parent; // Get the parent group (the actual room)

        if (roomGroup && roomGroup.children) {
            roomGroup.children.forEach(child => {
                if (child.material && child.material.emissive) {
                    child.material.emissive.setHex(0x000000); // Reset emissive for all parts
                }
                if (child.geometry && child.geometry.type === 'BoxGeometry') { // Apply color to visible parts
                    if (day < currentLevel) {
                        child.material.color.setHex(COLORS.nodeCompleted);
                    } else if (day === currentLevel) {
                        child.material.color.setHex(COLORS.nodeCurrent);
                        child.material.emissive.setHex(0x555500); // Current node glows
                    } else if (day <= currentLevel + 1) { // Unlock next day (current day + 1)
                        child.material.color.setHex(COLORS.nodeUnlocked);
                    } else {
                        child.material.color.setHex(COLORS.nodeLocked);
                    }
                }
            });
        }

        // Update text color based on node state
        if (roomGroup.userData.dayTextSprite) {
            const dayTextSprite = roomGroup.userData.dayTextSprite;
            const canvas = dayTextSprite.material.map.image;
            const context = canvas.getContext('2d');
            const textColor = (day < currentLevel) ? '#000000' : (day === currentLevel ? '#000000' : '#ffffff'); // Black for completed/current, white for locked/unlocked

            context.clearRect(0, 0, canvas.width, canvas.height); // Clear existing text
            context.font = "Bold 18px Inter"; // Use Inter font, consistent size
            context.fillStyle = textColor;
            context.textAlign = "center";
            context.textBaseline = "middle";
            context.fillText(`Day ${day}`, canvas.width / 2, canvas.height / 2); // Ensure "Day X" format
            dayTextSprite.material.map.needsUpdate = true; // Important: tell Three.js to update the texture
        }

        if (roomGroup.userData.challengeNameSprite) {
            const challengeNameSprite = roomGroup.userData.challengeNameSprite;
            const canvas = challengeNameSprite.material.map.image;
            const context = canvas.getContext('2d');
            const textColor = (day < currentLevel) ? '#000000' : (day === currentLevel ? '#000000' : '#ffffff'); // Black for completed/current, white for locked/unlocked

            context.clearRect(0, 0, canvas.width, canvas.height); // Clear existing text
            context.font = "Bold 14px Inter"; // Use Inter font, consistent size
            context.fillStyle = textColor;
            context.textAlign = "center";
            context.textBaseline = "middle";
            context.fillText(DAY_LOCATIONS[day - 1].name, canvas.width / 2, canvas.height / 2); // Use actual challenge name
            challengeNameSprite.material.map.needsUpdate = true; // Important: tell Three.js to update the texture
        }
    });
};