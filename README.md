# Cash Odds Backend API

Backend API server for Cash Odds app built with Express.js and SQLite.

## Features

- User authentication (register/login)
- Tips management (create, read, update, delete)
- Booking codes for tips
- Verification requests with image uploads (imgbb integration)
- Tipsters management
- Notifications system
- JWT-based authentication

## Getting Started

### Prerequisites

- Node.js (v14 or higher)
- npm or yarn

### Installation

1. Install dependencies:
```bash
npm install
```

2. Start the server:
```bash
# Production mode
npm start

# Development mode (with auto-reload)
npm run dev
```

3. The server will start on `http://localhost:3000`

### API Documentation

#### Authentication

- `POST /api/register` - Register new user
- `POST /api/login` - Login user

#### Tips

- `GET /api/tips` - Get all tips
- `GET /api/tips/:id` - Get single tip
- `POST /api/tips` - Create new tip (tipster only)
- `PATCH /api/tips/:id/status` - Update tip status
- `DELETE /api/tips/:id` - Delete tip

#### Verification Requests

- `POST /api/verification-requests` - Submit verification request
- `GET /api/verification-requests` - Get all requests (admin only)
- `GET /api/verification-requests/my` - Get my requests
- `PATCH /api/verification-requests/:id/status` - Update request status (admin only)

#### Tipsters

- `GET /api/tipsters` - Get all tipsters
- `GET /api/tipsters/:id` - Get tipster profile

#### Notifications

- `GET /api/notifications` - Get user notifications
- `PATCH /api/notifications/:id/read` - Mark as read
- `POST /api/notifications/read-all` - Mark all as read

## Database

The application uses SQLite database (`cashodds.db`) which is created automatically when the server starts.

### Tables

- **users** - User accounts
- **tips** - Betting tips
- **booking_codes** - Booking codes for tips
- **verification_requests** - Verification applications with image URLs
- **followers** - Tipster followers
- **notifications** - User notifications

## Environment Variables

Create a `.env` file in the backend directory:

```env
PORT=3000
JWT_SECRET=your-secret-key-here
```

## Image Uploads

Verification requests support image uploads via imgbb. The frontend uploads images to imgbb and sends the URLs to the backend.

Imgbb API Key: `3aa324878a27b8ebaea52aaa9b5aa01d`
