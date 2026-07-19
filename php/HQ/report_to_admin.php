<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../css/HQstyle.css">
</head>
<body>
    <h2 class="title">Report to Admin</h2>
    <p class="subtitle">Submit incident files, request technical help, or escalate critical terminal operational issues directly to global system administrators.</p>

    <div class="card">
        <h3>Submit a Support Ticket / Operational Escalation</h3>
        <p class="subtitle" style="margin-bottom: 15px;">Provide detailed information regarding system bugs, regional hardware malfunctions, or merchant disputes.</p>
        
        <form onsubmit="handleSubmitReport(event)">
            <div class="form-group">
                <label>Issue Classification / Topic</label>
                <select id="issue-topic" required>
                    <option value="">-- Select Category --</option>
                    <option value="pricing">Pricing Sync Error</option>
                    <option value="hardware">Terminal / Pump Hardware Fault</option>
                    <option value="access">Access Control & Credentials</option>
                    <option value="dispute">Transaction / Settlement Dispute</option>
                    <option value="other">Other Technical Request</option>
                </select>
            </div>

            <div class="form-group">
                <label>Impact Urgency Level</label>
                <select id="issue-urgency" required>
                    <option value="low">Low - General Question / Routine Update</option>
                    <option value="medium">Medium - System anomaly but business operational</option>
                    <option value="high">High - Business Critical / Station Outage</option>
                </select>
            </div>

            <div class="form-group">
                <label>Detailed Description</label>
                <textarea id="issue-description" rows="6" placeholder="Please describe the exact issue, steps to reproduce, or key terminal details..." style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-family: inherit; font-size: 0.95rem;" required></textarea>
            </div>

            <div class="form-group">
                <label>Attach Evidence / Screen Capture (Optional)</label>
                <input type="file" accept="image/*,application/pdf">
            </div>

            <button type="submit" class="btn btn-danger">Dispatch Report to Admin</button>
        </form>
    </div>

    <!-- Ticket History Log -->
    <!-- <div class="card">
        <h3>My Submitted Tickets</h3>
        <p class="subtitle" style="margin-bottom: 12px;">Track current resolution progress of previously submitted admin escalations.</p>
        
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border); text-align: left; font-size: 0.9rem;">
                    <th style="padding: 10px 8px;">Ticket ID</th>
                    <th style="padding: 10px 8px;">Topic</th>
                    <th style="padding: 10px 8px;">Urgency</th>
                    <th style="padding: 10px 8px;">Status</th>
                </tr>
            </thead>
            <tbody id="ticket-history">
                <tr style="border-bottom: 1px solid var(--border); font-size: 0.9rem;">
                    <td style="padding: 10px 8px; font-family: monospace;">#TKT-8902</td>
                    <td style="padding: 10px 8px;">Pricing Sync Error</td>
                    <td style="padding: 10px 8px;"><span style="color: var(--danger); font-weight: bold;">High</span></td>
                    <td style="padding: 10px 8px;"><span style="color: var(--success); font-weight: bold;">Resolved</span></td>
                </tr>
            </tbody>
        </table>
    </div> -->

    <script>
        function handleSubmitReport(e) {
            e.preventDefault();
            
            const topic = document.getElementById('issue-topic').value;
            const urgency = document.getElementById('issue-urgency').value;
            const description = document.getElementById('issue-description').value;

            // Generate a random ticket ID
            const ticketId = 'TKT-' + Math.floor(1000 + Math.random() * 9000);

            // Add the new row to our UI table safely
            const tbody = document.getElementById('ticket-history');
            const newRow = document.createElement('tr');
            newRow.style.borderBottom = '1px solid var(--border)';
            newRow.style.fontSize = '0.9rem';

            // Set color based on urgency selection
            let urgencyColor = 'var(--text)';
            if(urgency === 'high') urgencyColor = 'var(--danger)';
            else if(urgency === 'medium') urgencyColor = '#f59e0b'; // Amber

            newRow.innerHTML = `
                <td style="padding: 10px 8px; font-family: monospace;">#${ticketId}</td>
                <td style="padding: 10px 8px;">${topic.charAt(0).toUpperCase() + topic.slice(1)}</td>
                <td style="padding: 10px 8px;"><span style="color: ${urgencyColor}; font-weight: bold;">${urgency.toUpperCase()}</span></td>
                <td style="padding: 10px 8px;"><span style="color: #3b82f6; font-weight: bold;">Pending Admin Review</span></td>
            `;

            tbody.prepend(newRow);

            alert(`Your escalation has been dispatched! Track your ticket reference: #${ticketId}`);
            
            // Clear inputs
            document.getElementById('issue-topic').value = '';
            document.getElementById('issue-urgency').value = 'low';
            document.getElementById('issue-description').value = '';
        }
    </script>
</body>
<footer class="text-white-50 text-center py-4 mt-5 small">&copy; 2026 Map & Pump | All Rights Reserved</footer>
</html>