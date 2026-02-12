<?php
// api/save_email.php

// This one line gives us the session, security, config, AND database connection.
require_once __DIR__ . '/../includes/init.php';

// Security Check: Redirect if user is not logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: index.php'); // Redirect to login page if not logged in
    exit;
}

// Get the logged-in user's name for personalization
$loggedInUserName = isset($_SESSION['name']) ? htmlspecialchars($_SESSION['name']) : 'EPOS hero!';
$current_user_level = $_SESSION['user_level'] ?? 'user';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Communications Calendar</title>
    <style>
        body {
            font-family: 'EPOS Basis', Arial, sans-serif;
            margin: 0;
            padding: 0;
            padding-top: 80px; /* Space for the fixed navigation bar */
            background-image: url('/../assets/images/emailposback.svg');
            background-size: cover;
            background-repeat: no-repeat;
            background-attachment: fixed;
            background-position: center center;
            background-color: #f5f5f5;
            min-height: 100vh;
            box-sizing: border-box;
            color: #333;
        }

        /* Styles for the fixed navigation bar */
        .main-nav {
            position: fixed;
            top: 0;
            left: 0;
            background-color: #3f1e31;
            color: white;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            border-bottom-left-radius: 15px;
            border-bottom-right-radius: 15px;
            z-index: 1000;
            width: fit-content;
            max-width: 90%;
            flex-wrap: wrap;
            justify-content: space-between;
        }

        .main-nav .nav-logo {
            height: 40px;
            width: auto;
        }

        .main-nav .nav-title {
            font-size: 1.1em;
            font-weight: bold;
            white-space: nowrap;
            margin-right: auto;
        }

        .nav-links {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 5px 10px;
            border-radius: 5px;
            transition: background-color 0.3s ease;
            white-space: nowrap;
        }

        .nav-links a:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }

        @media (max-width: 768px) {
            .main-nav {
                padding: 10px 15px;
                gap: 10px;
                font-size: 0.9em;
                width: 100%;
                box-sizing: border-box;
                justify-content: center;
                border-radius: 0;
            }
            .main-nav .nav-logo {
                height: 30px;
            }
            .main-nav .nav-title {
                font-size: 1em;
                margin-right: 0;
            }
            .nav-links {
                width: 100%;
                justify-content: center;
                margin-top: 10px;
                gap: 10px;
            }
            .nav-links a {
                flex-grow: 1;
                text-align: center;
                padding: 8px 5px;
            }
            body {
                padding-top: 120px;
            }
        }

        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        h1, h2 {
            text-align: center;
            color: #00242a;
            margin-bottom: 20px;
        }

        .filters {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .filters label {
            font-weight: bold;
            margin-right: 10px;
        }

        .filters select {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 1em;
            background-color: #f8f8f8;
            cursor: pointer;
        }

        .calendar-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .calendar-nav button {
            background-color: #00242a;
            color: #fff;
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1em;
        }

        .calendar-nav button:hover {
            background-color: #00353d;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
            overflow: hidden;
        }

        .calendar-day-name, .calendar-date {
            background-color: #f0f0f0;
            padding: 10px 5px;
            text-align: center;
            font-weight: bold;
            border-bottom: 1px solid #ddd;
            border-right: 1px solid #ddd;
        }

        .calendar-day-name {
            background-color: #00242a;
            color: white;
            font-size: 0.9em;
            text-transform: uppercase;
        }

        .calendar-date {
            background-color: #ffffff;
            min-height: 100px;
            display: flex;
            flex-direction: column;
            align-items: flex-start; /* Align content to the top-left */
            justify-content: flex-start;
            padding: 5px;
            cursor: pointer;
            transition: background-color 0.2s ease;
            position: relative; /* For absolute positioning of number */
        }

        .calendar-date:hover {
            background-color: #e0f7fa; /* Light blue on hover */
        }

        .calendar-date.current-month {
            /* No specific background, remains white */
        }

        .calendar-date.other-month {
            background-color: #f8f8f8; /* Slightly grey for other months */
            color: #aaa; /* Faded text for other months */
        }

        .calendar-date.selected-day {
            background-color: #d1ecf1; /* Highlight selected day */
            border: 2px solid #00a399;
        }

        .day-number {
            font-size: 1.2em;
            font-weight: bold;
            color: #00242a;
            width: 100%; /* Take full width of day cell */
            text-align: right; /* Align number to top right */
            padding-right: 5px;
            box-sizing: border-box;
            position: absolute;
            top: 5px;
            right: 5px;
        }

        .event-list-container {
            margin-top: 30px;
            border-top: 1px solid #eee;
            padding-top: 20px;
        }

        .event-list-title {
            text-align: center;
            color: #00242a;
            margin-bottom: 20px;
        }

        .event-item {
            background-color: #e6f7ff; /* Light blue background for events */
            border: 1px solid #b3e0ff;
            border-left: 5px solid #007bff; /* Blue accent border */
            padding: 10px 15px;
            margin-bottom: 10px;
            border-radius: 4px;
            display: flex;
            flex-direction: column;
            font-size: 0.9em;
        }

        .event-item strong {
            color: #00242a;
            font-size: 1.1em;
            margin-bottom: 5px;
        }

        .event-item p {
            margin: 0;
            color: #555;
            line-height: 1.4;
            display: flex; /* For inline flag and text */
            align-items: center;
        }

        .event-item a.edit-link {
            display: inline-block;
            background-color: #00a399;
            color: white;
            padding: 5px 10px;
            border-radius: 3px;
            text-decoration: none;
            margin-top: 10px;
            align-self: flex-end; /* Align to the right */
            transition: background-color 0.2s ease;
        }

        .event-item a.edit-link:hover {
            background-color: #008f82;
        }

        .no-events {
            text-align: center;
            color: #777;
            padding: 20px;
        }

        /* Styles for flags */
        .flag-icon {
            width: 20px; /* Adjust size as needed */
            height: auto;
            margin-right: 8px; /* Space between flag and text */
            vertical-align: middle;
            border: 1px solid #eee; /* Subtle border for flags */
            border-radius: 2px;
        }

        /* Modal for messages */
        .modal-overlay {
          position: fixed;
          top: 0;
          left: 0;
          width: 100%;
          height: 100%;
          background-color: rgba(0, 0, 0, 0.6);
          display: none;
          justify-content: center;
          align-items: center;
          z-index: 1005;
          visibility: hidden;
          opacity: 0;
          transition: visibility 0s, opacity 0.3s;
        }
        .modal-overlay.visible {
          visibility: visible;
          opacity: 1;
          display: flex !important;
        }
        .modal-content {
          background-color: #fff;
          padding: 30px;
          border-radius: 8px;
          box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
          text-align: center;
          max-width: 500px;
          width: 90%;
          box-sizing: border-box;
        }
        .modal-content p {
          margin-bottom: 20px;
          font-size: 1.3em;
          font-weight: bold;
          color: #333;
        }
        .modal-content button {
          margin: 0 10px;
          background-color: #00242a;
          color: #fff;
          padding: 10px 20px;
          border: none;
          border-radius: 4px;
          cursor: pointer;
          font-size: 16px;
        }
        .modal-content button:hover {
          background-color: #00353d;
        }
    </style>
</head>
<body>

    <!-- Navigation Bar -->
    <nav class="main-nav">
        <a href="../emailpos.php"><img src="../assets/images/emailpos.svg" alt="EmailPOS Logo" class="nav-logo"></a>
        <span class="nav-title">Email Communications Calendar</span>
        <div class="nav-links">
            <a href="emailpos.php">Back to Editor</a>
            <?php if ($current_user_level === 'admin'): ?>
                <a href="admin_panel.php">Manage Users</a>
                <a href="create_user.php">Create New User</a>
            <?php endif; ?>
            <a href="logout.php">Logout</a>
        </div>
    </nav>

    <div class="container">
        <h1>Email Communications Calendar</h1>

        <div class="filters">
            <div>
                <label for="filterRegion">Region:</label>
                <select id="filterRegion">
                    <option value="">All Regions</option>
                    <option value="AMER">AMER</option>
                    <option value="ANZ">ANZ</option>
                    <option value="APAC">APAC</option>
                    <option value="BNL">BNL</option>
                    <option value="CANADA">CANADA</option>
                    <option value="DACH">DACH</option>
                    <option value="FRANCE">FRANCE</option>
                    <option value="INDIA">INDIA</option>
                    <option value="NORD">NORD</option>
                    <option value="UKI">UKI</option>
                </select>
            </div>
            <div>
                <label for="filterAudience">Audience:</label>
                <select id="filterAudience">
                    <option value="">All Audiences</option>
                    <option value="channel">Channel</option>
                    <option value="end_user">End User</option>
                    <option value="channel_end_user">Channel + End User</option>
                    <option value="internal">Internal</option>
                    <option value="other">Other</option>
                </select>
            </div>
        </div>

        <div class="calendar">
            <div class="calendar-nav">
                <button id="prevMonth">Previous</button>
                <h2 id="currentMonthYear"></h2>
                <button id="nextMonth">Next</button>
            </div>
            <div class="calendar-grid" id="calendarGrid">
                <div class="calendar-day-name">Sun</div>
                <div class="calendar-day-name">Mon</div>
                <div class="calendar-day-name">Tue</div>
                <div class="calendar-day-name">Wed</div>
                <div class="calendar-day-name">Thu</div>
                <div class="calendar-day-name">Fri</div>
                <div class="calendar-day-name">Sat</div>
            </div>
        </div>

        <div class="event-list-container">
            <h2 id="eventListTitle" class="event-list-title">Emails for Selected Day</h2>
            <div id="eventList">
                <p class="no-events">Select a day on the calendar to see emails for that date.</p>
            </div>
        </div>
    </div>

    <!-- Custom Modal for Messages -->
    <div class="modal-overlay" id="customModalOverlay">
      <div class="modal-content" id="customModalContent">
        <p id="customModalMessage"></p>
        <div class="modal-buttons">
            <button id="customModalCloseBtn">Close</button>
        </div>
      </div>
    </div>

    <script>
        // Custom Modal Functions (replicated from emailpos.php for standalone functionality)
        function showMessage(message) {
          const modalOverlay = document.getElementById('customModalOverlay');
          const modalMessage = document.getElementById('customModalMessage');
          const closeBtn = document.getElementById('customModalCloseBtn');

          modalMessage.textContent = message;
          closeBtn.onclick = () => {
            modalOverlay.classList.remove('visible');
          };
          modalOverlay.classList.add('visible');

          // Auto-dismiss after 4 seconds
          setTimeout(() => {
            if (modalOverlay.classList.contains('visible')) {
                modalOverlay.classList.remove('visible');
            }
          }, 4000);
        }

        let allEmails = []; // Stores all fetched emails
        let currentMonth = new Date().getMonth();
        let currentYear = new Date().getFullYear();
        let selectedDay = null; // To keep track of the selected day for event list

        const filterRegion = document.getElementById('filterRegion');
        const filterAudience = document.getElementById('filterAudience');
        const calendarGrid = document.getElementById('calendarGrid');
        const currentMonthYearDisplay = document.getElementById('currentMonthYear');
        const eventList = document.getElementById('eventList');
        const eventListTitle = document.getElementById('eventListTitle');

        // Map region codes to Flagpedia country codes
        const regionToCountryCodeMap = {
            'AMER': 'us',
            'ANZ': 'au',
            'APAC': 'sg',
            'BNL': 'nl',
            'CANADA': 'ca',
            'DACH': 'de',
            'FRANCE': 'fr',
            'INDIA': 'in',
            'NORD': 'dk',
            'UKI': 'gb' // United Kingdom
        };

        // Function to get flag HTML for a given region
        function getFlagHtml(region) {
            const countryCode = regionToCountryCodeMap[region];
            if (countryCode) {
                const flagUrl = `https://flagpedia.net/data/flags/mini/${countryCode}.png`;
                return `<img src="${flagUrl}" alt="${region} Flag" class="flag-icon">`;
            }
            return ''; // Return empty string if no flag found for region
        }


        // Function to fetch all emails from the server
        async function fetchAllEmails() {
            try {
                const response = await fetch('get_all_emails.php');
                const result = await response.json();

                if (response.ok && result.success) {
                    allEmails = result.emails;
                    console.log("Fetched emails:", allEmails);
                    renderCalendar(); // Render calendar with fetched data
                } else {
                    showMessage('Failed to fetch emails: ' + (result.message || 'Unknown error.'));
                }
            } catch (error) {
                showMessage('Error fetching emails: ' + error.message);
                console.error("Error fetching emails:", error);
            }
        }

        // Function to render the calendar grid and populate with events
        function renderCalendar() {
            calendarGrid.innerHTML = `
                <div class="calendar-day-name">Sun</div>
                <div class="calendar-day-name">Mon</div>
                <div class="calendar-day-name">Tue</div>
                <div class="calendar-day-name">Wed</div>
                <div class="calendar-day-name">Thu</div>
                <div class="calendar-day-name">Fri</div>
                <div class="calendar-day-name">Sat</div>
            `; // Reset grid

            const firstDayOfMonth = new Date(currentYear, currentMonth, 1);
            const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
            const firstDayOfWeek = firstDayOfMonth.getDay(); // 0 for Sunday, 1 for Monday

            currentMonthYearDisplay.textContent = firstDayOfMonth.toLocaleString('en-US', { month: 'long', year: 'numeric' });

            // Add empty cells for days before the 1st of the month
            for (let i = 0; i < firstDayOfWeek; i++) {
                // Calculate date for previous month's trailing days
                const prevMonthDays = new Date(currentYear, currentMonth, 0).getDate();
                const day = prevMonthDays - (firstDayOfWeek - 1 - i);
                const prevMonthDate = new Date(currentYear, currentMonth -1, day);
                const dayElement = document.createElement('div');
                dayElement.classList.add('calendar-date', 'other-month');
                dayElement.innerHTML = `<span class="day-number">${day}</span>`;
                calendarGrid.appendChild(dayElement);
            }

            // Add cells for each day of the current month
            for (let day = 1; day <= daysInMonth; day++) {
                const date = new Date(currentYear, currentMonth, day);
                const dayElement = document.createElement('div');
                dayElement.classList.add('calendar-date', 'current-month');
                dayElement.innerHTML = `<span class="day-number">${day}</span>`;
                dayElement.dataset.date = date.toISOString().split('T')[0]; // Store date asYYYY-MM-DD

                // Apply selected state
                if (selectedDay && selectedDay.toDateString() === date.toDateString()) {
                    dayElement.classList.add('selected-day');
                }

                // Add click listener to show events for that day
                dayElement.addEventListener('click', () => {
                    selectedDay = date; // Update selected day
                    renderCalendar(); // Re-render to highlight selected day
                    displayEventsForSelectedDay(); // Show events for the selected day
                });

                // Populate with events
                const eventsForDay = filterEmails(date);
                eventsForDay.forEach(email => {
                    const eventDiv = document.createElement('div');
                    eventDiv.classList.add('event-item');
                    eventDiv.style.cursor = 'pointer'; // Make it clear it's clickable

                    // Use fallbacks for all potentially undefined properties
                    const subjectLineDisplay = email.subjectLine || 'No Subject';
                    const audienceDisplay = (email.audience || 'N/A').replace(/_/g, ' ');
                    const regionDisplay = email.region || 'N/A';
                    const sendTimeDisplay = email.sendTime ? new Date(email.sendTime).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'N/A';
                    const referenceCode = email.referenceCode || '';

                    const flagHtml = getFlagHtml(regionDisplay); // Get the flag HTML

                    eventDiv.innerHTML = `
                        <strong>${subjectLineDisplay}</strong>
                        <p>Audience: ${audienceDisplay}</p>
                        <p>${flagHtml}Region: ${regionDisplay}</p>
                        <p>Send Time: ${sendTimeDisplay}</p>
                    `;
                    // Add click handler to navigate to editor
                    eventDiv.addEventListener('click', (e) => {
                        e.stopPropagation(); // Prevent click from bubbling to day element
                        if (referenceCode) {
                            window.location.href = `emailpos.php?code=${referenceCode}`;
                        } else {
                            showMessage('Cannot edit this email: No reference code available.');
                        }
                    });
                    dayElement.appendChild(eventDiv);
                });

                calendarGrid.appendChild(dayElement);
            }

            // Add empty cells for days after the last day of the month
            const lastDayDate = new Date(currentYear, currentMonth, daysInMonth);
            let remainingDays = 7 - (lastDayDate.getDay() + 1); // Days until end of week (0-6)
            if (remainingDays === 7) remainingDays = 0; // If month ends on Saturday, no trailing days needed for current row

            // Calculate total cells needed (42 for 6 weeks, or 35 for 5 weeks)
            const totalCells = Math.ceil((firstDayOfWeek + daysInMonth) / 7) * 7;
            remainingDays = totalCells - (firstDayOfWeek + daysInMonth);


            for (let i = 1; i <= remainingDays; i++) {
                const nextMonthDate = new Date(currentYear, currentMonth + 1, i);
                const dayElement = document.createElement('div');
                dayElement.classList.add('calendar-date', 'other-month');
                dayElement.innerHTML = `<span class="day-number">${i}</span>`;
                calendarGrid.appendChild(dayElement);
            }

            // After rendering the calendar grid, re-display events for the currently selected day
            displayEventsForSelectedDay();
        }

        // Function to filter emails based on current month, region, and audience
        function filterEmails(date = null) {
            const selectedRegion = filterRegion.value;
            const selectedAudience = filterAudience.value;

            return allEmails.filter(email => {
                // Use a fallback for email.sendTime if it's missing or invalid
                const emailSendTime = email.sendTime || '1970-01-01T00:00:00'; // Default to a very old date if missing
                const emailDate = new Date(emailSendTime);

                // Check for valid date parsing before proceeding
                if (isNaN(emailDate.getTime())) {
                    console.warn(`Invalid sendTime for email with referenceCode ${email.referenceCode || 'N/A'}: ${email.sendTime}. Skipping this email for calendar filtering.`);
                    return false; // Skip emails with invalid dates
                }

                const matchesMonth = emailDate.getMonth() === currentMonth && emailDate.getFullYear() === currentYear;

                // If a specific date is provided (for day click), filter by that date
                const matchesDay = date ? (emailDate.toDateString() === date.toDateString()) : true;

                // Use a fallback for email.audience and email.region if they are undefined
                const emailRegion = email.region || '';
                const emailAudience = email.audience || '';

                const matchesRegion = selectedRegion === "" || emailRegion === selectedRegion;
                const matchesAudience = selectedAudience === "" || emailAudience === selectedAudience;

                return matchesMonth && matchesDay && matchesRegion && matchesAudience;
            });
        }

        // Function to display events in the list view for the selected day
        function displayEventsForSelectedDay() {
            eventList.innerHTML = ''; // Clear previous events

            if (selectedDay) {
                eventListTitle.textContent = `Emails for ${selectedDay.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}`;
                const eventsOnSelectedDay = filterEmails(selectedDay);

                if (eventsOnSelectedDay.length > 0) {
                    eventsOnSelectedDay.sort((a, b) => {
                        const timeA = new Date(a.sendTime || '1970-01-01T00:00:00').getTime();
                        const timeB = new Date(b.sendTime || '1970-01-01T00:00:00').getTime();
                        return timeA - timeB;
                    });

                    eventsOnSelectedDay.forEach(email => {
                        const eventDiv = document.createElement('div');
                        eventDiv.classList.add('event-item');
                        // Use fallbacks for all potentially undefined properties
                        const subjectLineDisplay = email.subjectLine || 'No Subject';
                        const audienceDisplay = (email.audience || 'N/A').replace(/_/g, ' ');
                        const regionDisplay = email.region || 'N/A';
                        const sendTimeDisplay = email.sendTime ? new Date(email.sendTime).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'N/A';
                        const referenceCode = email.referenceCode || '';

                        const flagHtml = getFlagHtml(regionDisplay); // Get the flag HTML

                        eventDiv.innerHTML = `
                            <strong>${subjectLineDisplay}</strong>
                            <p>Audience: ${audienceDisplay}</p>
                            <p>${flagHtml}Region: ${regionDisplay}</p>
                            <p>Send Time: ${sendTimeDisplay}</p>
                            <a href="emailpos.php?code=${referenceCode}" target="_blank" class="edit-link">Edit Email</a>
                        `;
                        eventList.appendChild(eventDiv);
                    });
                } else {
                    eventList.innerHTML = '<p class="no-events">No emails scheduled for this day with current filters.</p>';
                }
            } else {
                eventListTitle.textContent = 'Emails for Selected Day';
                eventList.innerHTML = '<p class="no-events">Select a day on the calendar to see emails for that date.</p>';
            }
        }

        // Event listeners for month navigation
        document.getElementById('prevMonth').addEventListener('click', () => {
            currentMonth--;
            if (currentMonth < 0) {
                currentMonth = 11;
                currentYear--;
            }
            selectedDay = null; // Reset selected day when month changes
            renderCalendar();
        });

        document.getElementById('nextMonth').addEventListener('click', () => {
            currentMonth++;
            if (currentMonth > 11) {
                currentMonth = 0;
                currentYear++;
            }
            selectedDay = null; // Reset selected day when month changes
            renderCalendar();
        });

        // Event listeners for filters
        filterRegion.addEventListener('change', () => {
            selectedDay = null; // Reset selected day when filters change
            renderCalendar();
        });
        filterAudience.addEventListener('change', () => {
            selectedDay = null; // Reset selected day when filters change
            renderCalendar();
        });

        // Initial load
        document.addEventListener('DOMContentLoaded', () => {
            fetchAllEmails();
        });
    </script>
</body>
</html>