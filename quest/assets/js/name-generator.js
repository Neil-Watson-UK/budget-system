/**
 * name-generator.js
 *
 * Contains logic for generating unique, anonymous player names for the EPOS Holiday Harmony Quest game.
 * Functions are exposed globally for use by non-module scripts.
 *
 * @version 1.0.2
 * @date 2025-07-21
 * @author Gemini Assistant
 */

// Wrap the entire script in an IIFE to prevent global variable conflicts
(function() {
    // Word pool for anonymous names.
    // This pool is designed to generate a large number of unique combinations (adjective + noun)
    // to accommodate up to 3,000 players.
    const wordPool = {
        adjectives: [
            'Clear', 'Focused', 'Dynamic', 'Optimal', 'Seamless', 'Vivid', 'Silent', 'Agile', 'Connected', 'Resilient',
            'Smart', 'Crisp', 'Adaptive', 'Powerful', 'Reliable', 'Ergonomic', 'Intuitive', 'Unified', 'Hybrid', 'Mobile',
            'Dedicated', 'Premium', 'Superior', 'Excellent', 'Enhanced', 'Crystal', 'Audible', 'Intelligent', 'Strategic',
            'Productive', 'Efficient', 'Global', 'Innovative', 'Leading', 'Proactive', 'Responsive', 'Versatile', 'Wireless',
            'Wired', 'Professional', 'Business', 'Enterprise', 'Digital', 'Acoustic', 'Vocal', 'Communicate', 'Collaborate',
            'Listen', 'Talk', 'Work', 'Achieve', 'Succeed', 'Excel', 'Master', 'Boost', 'Empower', 'Optimize', 'Streamline',
            'Integrate', 'Adapt', 'Expand', 'Impact', 'Harmony', 'Quest', 'Adventure', 'Solution', 'System', 'Platform'
        ],
        nouns: [
            'Vision', 'Sound', 'Echo', 'Stream', 'Wave', 'Core', 'Link', 'Node', 'Path', 'Summit',
            'Peak', 'Edge', 'Flow', 'Pulse', 'Grid', 'Nexus', 'Hub', 'Bridge', 'Tower', 'Beacon',
            'Sphere', 'Orb', 'Matrix', 'Portal', 'Gateway', 'Network', 'System', 'Framework', 'Structure', 'Domain',
            'Zone', 'Realm', 'Dimension', 'Space', 'Frontier', 'Horizon', 'Venture', 'Initiative', 'Project', 'Mission',
            'Journey', 'Expedition', 'Odyssey', 'Voyage', 'Exploration', 'Discovery', 'Insight', 'Clarity', 'Focus', 'Precision',
            'Excellence', 'Pinnacle', 'Zenith', 'Apex', 'Crest', 'Stride', 'Momentum', 'Catalyst', 'Synergy', 'Fusion',
            'Element', 'Factor', 'Component', 'Module', 'Unit', 'Segment', 'Aspect', 'Facet', 'Angle', 'Perspective'
        ]
    };

    // Set to keep track of already generated names to ensure uniqueness.
    const generatedNames = new Set();

    /**
     * Defines the NameGenerator object with its methods.
     * This ensures the entire object is constructed before being assigned to window.
     */
    const NameGenerator = {
        /**
         * Generates a unique player name by combining a random adjective and noun from predefined pools.
         * Ensures the generated name has not been used before in the current session.
         *
         * @returns {string} A unique player name (e.g., "Swift Falcon", "Silent Echo").
         */
        generateUniquePlayerName: function() {
            if (wordPool.adjectives.length === 0 || wordPool.nouns.length === 0) {
                console.error("Name generator word pools are empty. Cannot generate names.");
                return "AnonymousPlayer"; // Fallback name
            }

            let name;
            let attempts = 0;
            const maxAttempts = wordPool.adjectives.length * wordPool.nouns.length * 2; // Prevent infinite loops

            do {
                const randomAdjective = wordPool.adjectives[Math.floor(Math.random() * wordPool.adjectives.length)];
                const randomNoun = wordPool.nouns[Math.floor(Math.random() * wordPool.nouns.length)];
                name = `${randomAdjective} ${randomNoun}`;
                attempts++;

                if (attempts > maxAttempts) {
                    console.warn("Could not generate a unique name after many attempts. Possible name pool exhaustion.");
                    // Fallback: append a number if unique names are exhausted
                    return `${name}-${Math.floor(Math.random() * 1000)}`;
                }
            } while (generatedNames.has(name));

            generatedNames.add(name);
            return name;
        },

        /**
         * Resets the set of generated names.
         * This can be useful for starting a new game session or if the name pool needs to be refreshed.
         */
        resetGeneratedNames: function() {
            generatedNames.clear();
        }
    };

    // Expose the complete NameGenerator object globally
    window.NameGenerator = NameGenerator;

    console.log("name-generator.js loaded and window.NameGenerator is available with functions.");
})();
