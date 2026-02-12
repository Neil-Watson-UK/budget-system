/**
 * main2.js
 *
 * This is the main JavaScript file for the EPOS Holiday Harmony Quest game.
 * It manages the overall game flow, UI interactions, player state,
 * and integrates with individual day-specific game modules (like day1.js).
 *
 * @version 1.0.48
 * @date 2025-07-24
 * @author Gemini Assistant
 */

// Global game state variables
let playerName = "Guest Elf";
let globalScore = 0;
// UNLOCK DAYS: Setting currentDay to 7 to unlock days up to Day 7.
let currentDay = 7; // Represents the current unlocked day/level
let selectedDay = 1; // Represents the day currently selected on the map/carousel
let playerSuperpower = null; // Stores the selected superpower (IMPACT, EXPAND, ADAPT)
const TOTAL_DAYS = 15; // Total number of days/levels in the game

// UI Element References (initialized to null, assigned in DOMContentLoaded)
let introScreen;
let nameGenerationScreen;
let playerNameInput;
let generateNameButton;
let confirmNameButton;
let superpowerSelectionScreen;
let superpowerCards;
let currentDaySuperpower; // Span to display current day on superpower screen
let startChallengeButtonSuperpower; // Button to start challenge after superpower selection
let gameScreen;
let gameCanvas;
let playerInfoDisplay;
let playerNameDisplay; // This is the span for displaying name
let scoreDisplay;
let levelDisplay;
let activeSuperpowerDisplay;
let pauseButton;
let restartButton;
let saveGameButton;
let questCarouselScreen;
let questCardContainer;
let selectDayButton;
let startNewGameButton;
let continueGameButton;
let continueCodeInput;

// Message Box elements
let messageBox;
let messageBoxTitle;
let messageBoxContent;
let messageBoxOkBtn;

// Game-specific functions from other modules
const gameModules = {
    1: window.Day1Game,
    2: window.Day2Game,
    3: window.Day3Game,
    4: window.Day4Game,
    5: window.Day5Game,
    6: window.Day6Game,
    7: window.Day7Game
    // Add more days as they are implemented
};

// Expose a function to individual game modules to call when they complete
window.advanceToNextLevel = advanceToNextLevel;
window.restartCurrentGame = restartCurrentGame;
window.getGlobalScore = () => globalScore;
window.updateGlobalScore = updateGlobalScore;
window.showMessageBoxRef = showMessageBox; // Expose message box function for modules

// --- UI Display & Navigation Functions ---

/**
 * Updates the UI elements based on the current player state.
 */
function updatePlayerUI() {
    if (playerNameDisplay) {
        playerNameDisplay.textContent = playerName;
    }
    if (scoreDisplay) {
        scoreDisplay.textContent = globalScore;
    }
    if (levelDisplay) {
        levelDisplay.textContent = currentDay;
    }
    if (activeSuperpowerDisplay) {
        activeSuperpowerDisplay.textContent = playerSuperpower || 'N/A';
    }
    if (playerInfoDisplay) {
        playerInfoDisplay.classList.remove('hidden');
    }
}

/**
 * Shows a specific screen and hides all others.
 * @param {string} screenId The ID of the screen to show.
 */
function showScreen(screenId) {
    const screens = [
        introScreen, nameGenerationScreen, superpowerSelectionScreen,
        gameScreen, questCarouselScreen
    ];
    screens.forEach(screen => {
        if (screen) {
            screen.classList.add('hidden');
        }
    });

    const targetScreen = document.getElementById(screenId);
    if (targetScreen) {
        targetScreen.classList.remove('hidden');
    }
}

/**
 * Displays a modal message box.
 * @param {string} title The title of the message.
 * @param {string} content The HTML content of the message. Can be simple text.
 * @param {function} [onOk] Optional callback function to execute when OK is clicked.
 * @param {number} [autoHideTimeout=0] Optional timeout in ms to automatically hide the box. 0 means no auto-hide.
 * @param {boolean} [isHtmlContent=false] Flag to indicate if the content is HTML.
 */
function showMessageBox(title, content, onOk, autoHideTimeout = 0, isHtmlContent = false) {
    if (!messageBox || !messageBoxTitle || !messageBoxContent || !messageBoxOkBtn) {
        console.error("Message box elements not found.");
        return;
    }

    messageBoxTitle.innerHTML = title;
    if (isHtmlContent) {
        messageBoxContent.innerHTML = content;
    } else {
        messageBoxContent.textContent = content;
    }

    messageBox.classList.add('show');
    messageBoxOkBtn.onclick = () => {
        messageBox.classList.remove('show');
        if (onOk) {
            onOk();
        }
    };

    if (autoHideTimeout > 0) {
        setTimeout(() => {
            messageBox.classList.remove('show');
            if (onOk) {
                onOk();
            }
        }, autoHideTimeout);
    }
}

// --- Game Initialization & State Management ---

/**
 * Initializes the game state at the start of a new game.
 */
function startNewGame() {
    playerName = "Guest Elf";
    globalScore = 0;
    currentDay = 1;
    selectedDay = 1;
    playerSuperpower = null;
    showScreen('nameGenerationScreen');
    const newName = window.NameGenerator.generateUniquePlayerName();
    if (playerNameInput) {
        playerNameInput.value = newName;
    }
    updatePlayerUI();
}

/**
 * Loads the game state from Firestore using a reference code.
 * @param {string} code The 6-character reference code.
 */
async function loadGameFromCode(code) {
    if (!code || code.length !== 6) {
        showMessageBox('Error', 'Please enter a valid 6-character code.');
        return;
    }
    showMessageBox('Loading...', 'Please wait while we load your quest.', null);

    const db = window.firebaseDb;
    const appId = typeof __app_id !== 'undefined' ? __app_id : 'default-app-id';
    const docRef = doc(db, `artifacts/${appId}/public/data/saved_games`, code);
    try {
        const docSnap = await getDoc(docRef);
        if (docSnap.exists()) {
            const data = docSnap.data();
            playerName = data.playerName;
            globalScore = data.globalScore;
            currentDay = data.currentDay;
            selectedDay = data.currentDay;
            playerSuperpower = data.playerSuperpower;
            
            // Re-render the UI with the loaded state
            updatePlayerUI();
            updateQuestCarouselProgress(currentDay);
            
            showMessageBox('Quest Loaded!', 'Your journey continues from where you left off!');
        } else {
            showMessageBox('Load Failed', 'The provided quest code was not found. Please check the code and try again.');
        }
    } catch (e) {
        console.error("Error loading document: ", e);
        showMessageBox('Error', `Failed to load quest: ${e.message}`);
    }
}


/**
 * Saves the current game state to Firestore and generates a shareable code.
 */
async function saveGameAndGenerateCode() {
    if (!window.firebaseDb) {
        showMessageBox('Save Failed', 'Firebase is not initialized. Cannot save game.');
        return;
    }

    showMessageBox('Saving...', 'Please wait while your quest is saved. Do not close this window.', null);

    const db = window.firebaseDb;
    const appId = typeof __app_id !== 'undefined' ? __app_id : 'default-app-id';

    // Generate a unique 6-character code
    const characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    let code = '';
    for (let i = 0; i < 6; i++) {
        code += characters.charAt(Math.floor(Math.random() * characters.length));
    }

    const gameData = {
        playerName: playerName,
        globalScore: globalScore,
        currentDay: currentDay,
        playerSuperpower: playerSuperpower,
        lastUpdated: new Date().toISOString()
    };

    try {
        const docRef = doc(db, `artifacts/${appId}/public/data/saved_games`, code);
        await setDoc(docRef, gameData);

        showMessageBox('Quest Saved!', `Your quest has been saved! Share this code to continue later: <br><br><b>${code}</b>`, null, 0, true);
        console.log("Game state saved successfully with code: ", code);
    } catch (e) {
        console.error("Error saving document: ", e);
        showMessageBox('Save Failed', `An error occurred while saving your quest: ${e.message}`);
    }
}


/**
 * Renders the quest carousel with interactive cards for each day.
 */
function showQuestCarousel() {
    if (!questCardContainer) return;

    // Clear existing cards
    questCardContainer.innerHTML = '';

    const dayNames = {
        1: "Santa's Sleigh Service",
        2: "The Harmony Balancing Game",
        3: "The New Open Office",
        4: "Sound Block Harmony",
        5: "Office Crossy",
        6: "Santa's Bubble Pop Challenge",
        7: "The Virtual Meeting Room"
    };

    for (let i = 1; i <= TOTAL_DAYS; i++) {
        const isUnlocked = i <= currentDay;
        const isCompleted = i < currentDay;
        const isActive = i === selectedDay;

        const card = document.createElement('div');
        card.classList.add('quest-card');
        if (isUnlocked) card.classList.add('unlocked');
        if (isCompleted) card.classList.add('completed');
        if (isActive) card.classList.add('active');

        card.setAttribute('data-day', i);
        card.innerHTML = `
            <i class="${isCompleted ? 'fas fa-check-circle' : isUnlocked ? 'fas fa-lock-open' : 'fas fa-lock'} quest-status-icon"></i>
            <div class="font-bold text-lg">Day ${i}</div>
            <p>${dayNames[i] || 'Coming Soon...'}</p>
        `;
        questCardContainer.appendChild(card);

        if (isUnlocked) {
            card.addEventListener('click', () => {
                // Remove 'active' class from all cards
                document.querySelectorAll('.quest-card').forEach(c => c.classList.remove('active'));
                // Add 'active' class to the clicked card
                card.classList.add('active');
                selectedDay = i;
            });
        }
    }
    showScreen('questCarouselScreen');
    updatePlayerUI(); // Ensure player info bar is visible and up-to-date
}

/**
 * Updates the quest carousel to reflect new progress.
 * This function is called from advanceToNextLevel.
 * @param {number} newLevel The new current day/level.
 */
function updateQuestCarouselProgress(newLevel) {
    currentDay = newLevel; // Update global currentDay
    showQuestCarousel(); // Re-render the carousel with updated statuses
}

// --- Game Logic Hooks (called by UI events) ---

/**
 * Starts the selected game day.
 */
function startSelectedGame() {
    // Check if the selected day is unlocked
    if (selectedDay > currentDay) {
        showMessageBox('Locked', 'This quest is not yet unlocked! Complete previous days to continue your journey.');
        return;
    }

    // Check if the selected day has a game module
    if (!gameModules[selectedDay]) {
        showMessageBox('Coming Soon', `The challenge for Day ${selectedDay} is not yet available. Stay tuned!`);
        return;
    }

    // Show the game screen and start the game logic for the selected day
    showScreen('superpowerSelectionScreen');
    currentDaySuperpower.textContent = selectedDay;
}

/**
 * Handles the selection of a superpower and starts the game.
 * @param {string} superpower The selected superpower (IMPACT, EXPAND, ADAPT).
 */
function selectSuperpowerAndStart(superpower) {
    playerSuperpower = superpower;
    showScreen('gameScreen');
    updatePlayerUI();
    
    // Check if the game module exists and initialize it
    const gameModule = gameModules[selectedDay];
    if (gameModule && gameModule.initGame) {
        gameModule.initGame({
            canvas: gameCanvas,
            playerName: playerName,
            superpower: playerSuperpower,
            currentDay: selectedDay,
            showMessageBox: showMessageBox,
            displayGameStatus: updatePlayerUI,
            updateGlobalScore: updateGlobalScore,
            restartCurrentGame: restartCurrentGame,
            getGlobalScore: window.getGlobalScore,
            advanceToNextLevel: advanceToNextLevel,
        });
        gameModule.startGame();
    } else {
        showMessageBox('Error', 'Game module not found. Cannot start game.', () => {
            showQuestCarousel();
        });
    }
}

/**
 * Updates the global score and UI.
 * @param {number} points The points to add to the score.
 */
function updateGlobalScore(points) {
    globalScore += points;
    updatePlayerUI();
}

/**
 * Restarts the current game day.
 */
function restartCurrentGame() {
    const gameModule = gameModules[selectedDay];
    if (gameModule && gameModule.resetDay) {
        gameModule.resetDay({
            canvas: gameCanvas,
            playerName: playerName,
            superpower: playerSuperpower,
            currentDay: selectedDay,
            showMessageBox: showMessageBox,
            displayGameStatus: updatePlayerUI,
            updateGlobalScore: updateGlobalScore,
            restartCurrentGame: restartCurrentGame,
            getGlobalScore: window.getGlobalScore,
            advanceToNextLevel: advanceToNextLevel,
        });
    }
    showMessageBox('Day Restarted', 'The challenge for today has been restarted!', null, 1500);
}

/**
 * Advances the player to the next overall game level (Day 2, Day 3, etc.).
 * This is called by individual day modules upon successful completion.
 */
function advanceToNextLevel() {
    if (selectedDay === TOTAL_DAYS) {
        showMessageBox('Quest Complete!', 'Congratulations! You have completed all 15 days of the EPOS Holiday Harmony Quest!', null, 0);
        return;
    }

    // Unlock the next day
    currentDay = Math.min(selectedDay + 1, TOTAL_DAYS);
    selectedDay = currentDay;
    globalScore = 0; // Reset score for the new day
    playerSuperpower = null; // Reset superpower for the new day

    showMessageBox('Quest Continues!', `Prepare for Day ${currentDay}!`, () => {
        showQuestCarousel();
        updateQuestCarouselProgress(currentDay);
    }, 2000);
}

// --- Initialize the game when the DOM is fully loaded ---\
document.addEventListener('DOMContentLoaded', () => {
    // Get all UI element references
    introScreen = document.getElementById('introScreen');
    nameGenerationScreen = document.getElementById('nameGenerationScreen');
    playerNameInput = document.getElementById('playerNameInput');
    generateNameButton = document.getElementById('generateNameButton');
    confirmNameButton = document.getElementById('confirmNameButton');
    superpowerSelectionScreen = document.getElementById('superpowerSelectionScreen');
    superpowerCards = document.getElementById('superpowerCards');
    currentDaySuperpower = document.getElementById('currentDaySuperpower');
    startChallengeButtonSuperpower = document.getElementById('startChallengeButtonSuperpower');
    gameScreen = document.getElementById('gameScreen');
    gameCanvas = document.getElementById('gameCanvas');
    playerInfoDisplay = document.getElementById('playerInfo');
    playerNameDisplay = document.getElementById('playerNameDisplay');
    scoreDisplay = document.getElementById('scoreDisplay');
    levelDisplay = document.getElementById('levelDisplay');
    activeSuperpowerDisplay = document.getElementById('activeSuperpowerDisplay');
    pauseButton = document.getElementById('pauseButton');
    restartButton = document.getElementById('restartButton');
    saveGameButton = document.getElementById('saveGameButton');
    questCarouselScreen = document.getElementById('questCarouselScreen');
    questCardContainer = document.getElementById('questCardContainer');
    selectDayButton = document.getElementById('selectDayButton');
    startNewGameButton = document.getElementById('startNewGameButton');
    continueGameButton = document.getElementById('continueGameButton');
    continueCodeInput = document.getElementById('continueCodeInput');
    messageBox = document.getElementById('messageBox');
    messageBoxTitle = document.getElementById('messageBoxTitle');
    messageBoxContent = document.getElementById('messageBoxContent');
    messageBoxOkBtn = document.getElementById('messageBoxOkBtn');

    // Attach all event listeners
    if (startNewGameButton) {
        startNewGameButton.addEventListener('click', startNewGame);
    }
    if (continueGameButton) {
        continueGameButton.addEventListener('click', () => {
            const code = continueCodeInput.value.trim().toUpperCase();
            loadGameFromCode(code);
        });
    }
    if (generateNameButton && playerNameInput) {
        generateNameButton.addEventListener('click', () => {
            playerNameInput.value = window.NameGenerator.generateUniquePlayerName();
        });
    }
    if (confirmNameButton) {
        confirmNameButton.addEventListener('click', () => {
            const name = playerNameInput.value.trim();
            if (name) {
                playerName = name;
                showQuestCarousel();
            } else {
                showMessageBox('Name Required', 'Please generate or enter a player name.');
            }
        });
    }
    if (superpowerCards) {
        superpowerCards.addEventListener('click', (event) => {
            const card = event.target.closest('.superpower-card');
            if (card) {
                // Deselect all cards
                document.querySelectorAll('.superpower-card').forEach(c => c.classList.remove('selected'));
                // Select the clicked card
                card.classList.add('selected');
                // Make the start button visible
                startChallengeButtonSuperpower.classList.remove('hidden');
                playerSuperpower = card.dataset.superpower;
            }
        });
    }
    if (startChallengeButtonSuperpower) {
        startChallengeButtonSuperpower.addEventListener('click', () => {
            selectSuperpowerAndStart(playerSuperpower);
        });
    }
    if (selectDayButton) {
        selectDayButton.addEventListener('click', startSelectedGame);
    }
    if (pauseButton) {
        pauseButton.addEventListener('click', () => {
            const gameModule = gameModules[selectedDay];
            if (gameModule && gameModule.pauseGame) {
                gameModule.pauseGame();
            }
        });
    }
    if (restartButton) {
        restartButton.addEventListener('click', () => {
            const gameModule = gameModules[selectedDay];
            if (gameModule && gameModule.restartGame) {
                gameModule.restartGame();
            }
        });
    }
    if (saveGameButton) {
        saveGameButton.addEventListener('click', saveGameAndGenerateCode);
    }

    // Initial state: show the intro screen
    showScreen('introScreen');
});

})(); // End of IIFE
