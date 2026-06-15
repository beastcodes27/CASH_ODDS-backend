const express = require('express');
const cors = require('cors');
const helmet = require('helmet');
const rateLimit = require('express-rate-limit');
const { body, param, query, validationResult } = require('express-validator');
const sqlite3 = require('sqlite3').verbose();
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const envPath = path.join(__dirname, '.env');
if (fs.existsSync(envPath)) {
  const envLines = fs.readFileSync(envPath, 'utf8').split(/\r?\n/);
  for (const line of envLines) {
    const trimmedLine = line.trim();
    if (!trimmedLine || trimmedLine.startsWith('#')) {
      continue;
    }

    const separatorIndex = trimmedLine.indexOf('=');
    if (separatorIndex === -1) {
      continue;
    }

    const key = trimmedLine.slice(0, separatorIndex).trim();
    let value = trimmedLine.slice(separatorIndex + 1).trim();

    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }

    if (!(key in process.env)) {
      process.env[key] = value;
    }
  }
}

const JWT_SECRET = process.env.JWT_SECRET;
if (!JWT_SECRET) {
  console.error('FATAL: JWT_SECRET environment variable is required');
  process.exit(1);
}

const PORT = process.env.PORT || 3000;
const ALLOWED_ORIGIN = process.env.ALLOWED_ORIGIN || 'http://localhost:19006';

if (ALLOWED_ORIGIN === '*' || ALLOWED_ORIGIN.includes('*')) {
  console.error('FATAL: Wildcard CORS origin is not allowed');
  process.exit(1);
}
const FASTLIPA_API_URL = process.env.FASTLIPA_API_URL || 'https://api.fastlipa.com/api';
const FASTLIPA_AUTH_TOKEN = process.env.FASTLIPA_AUTH_TOKEN;

if (!FASTLIPA_AUTH_TOKEN || FASTLIPA_AUTH_TOKEN === 'your_fastlipa_auth_token_here') {
  console.warn('WARNING: FASTLIPA_AUTH_TOKEN is not set. Payment endpoints will be unavailable.');
}

const app = express();

const BCRYPT_ROUNDS = 12;

app.use(helmet());
app.use(cors({
  origin: ALLOWED_ORIGIN,
  methods: ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],
  allowedHeaders: ['Content-Type', 'Authorization'],
  credentials: false,
}));
app.use(express.json({ limit: '50kb' }));

const generalLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  max: 200,
  standardHeaders: true,
  legacyHeaders: false,
  message: { message: 'Too many requests, please try again later.' },
});

const authLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  max: 20,
  standardHeaders: true,
  legacyHeaders: false,
  message: { message: 'Too many authentication attempts, please try again later.' },
});

const paymentLimiter = rateLimit({
  windowMs: 60 * 1000,
  max: 5,
  standardHeaders: true,
  legacyHeaders: false,
  message: { message: 'Too many payment requests, please slow down.' },
});

app.use('/api', generalLimiter);

const validate = (validations) => {
  return async (req, res, next) => {
    for (const validation of validations) {
      const result = await validation.run(req);
      if (!result.isEmpty()) break;
    }
    const errors = validationResult(req);
    if (!errors.isEmpty()) {
      return res.status(422).json({ message: 'Validation failed', errors: errors.array() });
    }
    next();
  };
};

const handleDbError = (res, message, err) => {
  console.error(message, err);
  return res.status(500).json({ message: 'An internal server error occurred.' });
};

const parseJsonSafely = async (response) => {
  const text = await response.text();
  if (!text) {
    return null;
  }

  try {
    return JSON.parse(text);
  } catch (error) {
    return { raw: text };
  }
};

// Initialize SQLite Database
const db = new sqlite3.Database(path.join(__dirname, 'cashodds.db'));

// Create tables
db.serialize(() => {
  // Users table
  db.run(`CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    phone TEXT,
    role TEXT DEFAULT 'subscriber',
    verified INTEGER DEFAULT 0,
    bio TEXT,
    avatar TEXT,
    followers_count INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  )`);

  // Tips table
  db.run(`CREATE TABLE IF NOT EXISTS tips (
    id TEXT PRIMARY KEY,
    tipster_id TEXT NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    total_odds REAL NOT NULL,
    is_premium INTEGER DEFAULT 0,
    custom_price REAL,
    status TEXT DEFAULT 'pending',
    views INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tipster_id) REFERENCES users(id)
  )`);

  // Booking codes table
  db.run(`CREATE TABLE IF NOT EXISTS booking_codes (
    id TEXT PRIMARY KEY,
    tip_id TEXT NOT NULL,
    company TEXT NOT NULL,
    code TEXT NOT NULL,
    FOREIGN KEY (tip_id) REFERENCES tips(id) ON DELETE CASCADE
  )`);

  // Verification requests table
  db.run(`CREATE TABLE IF NOT EXISTS verification_requests (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    full_name TEXT NOT NULL,
    phone TEXT NOT NULL,
    email TEXT NOT NULL,
    experience TEXT NOT NULL,
    expertise TEXT NOT NULL,
    social_links TEXT,
    why_verify TEXT NOT NULL,
    id_image_url TEXT,
    selfie_image_url TEXT,
    status TEXT DEFAULT 'pending',
    applied_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
  )`);

  // Followers table
  db.run(`CREATE TABLE IF NOT EXISTS followers (
    id TEXT PRIMARY KEY,
    follower_id TEXT NOT NULL,
    tipster_id TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(follower_id, tipster_id),
    FOREIGN KEY (follower_id) REFERENCES users(id),
    FOREIGN KEY (tipster_id) REFERENCES users(id)
  )`);

  // Notifications table
  db.run(`CREATE TABLE IF NOT EXISTS notifications (
    id TEXT PRIMARY KEY,
    user_id TEXT,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    type TEXT,
    is_read INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
  )`);

  // Purchases table for tracking purchased tips
  db.run(`CREATE TABLE IF NOT EXISTS purchases (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    tip_id TEXT NOT NULL,
    amount REAL NOT NULL,
    payment_method TEXT DEFAULT 'mpesa',
    transaction_id TEXT,
    status TEXT DEFAULT 'completed',
    purchased_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (tip_id) REFERENCES tips(id),
    UNIQUE(user_id, tip_id)
  )`);

  console.log('Database tables created successfully');
});

// Auth Middleware
const authMiddleware = (req, res, next) => {
  const token = req.headers.authorization?.split(' ')[1];
  if (!token) {
    return res.status(401).json({ message: 'Authentication required' });
  }

  try {
    const decoded = jwt.verify(token, JWT_SECRET, { algorithms: ['HS256'] });
    req.user = decoded;
    next();
  } catch (error) {
    return res.status(401).json({ message: 'Invalid or expired token' });
  }
};

// ========== AUTH ROUTES ==========

// Register
app.post('/api/register', authLimiter, validate([
  body('name').trim().isLength({ min: 2, max: 100 }).withMessage('Name must be 2-100 characters'),
  body('email').isEmail().normalizeEmail().withMessage('A valid email is required'),
  body('password')
    .isLength({ min: 8, max: 128 }).withMessage('Password must be at least 8 characters')
    .matches(/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/).withMessage('Password must contain uppercase, lowercase, and a number'),
  body('phone').optional({ values: 'falsy' }).matches(/^\+?[0-9]{7,15}$/).withMessage('Invalid phone number'),
  body('role').optional().isIn(['subscriber', 'tipster']).withMessage('Invalid role'),
]), async (req, res) => {
  const { name, email, password, phone } = req.body;
  const role = 'subscriber';

  try {
    const hashedPassword = await bcrypt.hash(password, BCRYPT_ROUNDS);
    const id = crypto.randomUUID();

    db.run(
      'INSERT INTO users (id, name, email, password, phone, role) VALUES (?, ?, ?, ?, ?, ?)',
      [id, name, email, hashedPassword, phone, role],
      function(err) {
        if (err) {
          if (err.message && err.message.includes('UNIQUE constraint failed')) {
            return res.status(409).json({ message: 'Email already exists' });
          }
          return handleDbError(res, 'Error creating user', err);
        }

        const token = jwt.sign({ sub: id, email, role, jti: crypto.randomUUID() }, JWT_SECRET, {
          expiresIn: '2h',
          algorithm: 'HS256',
        });

        res.status(201).json({
          message: 'Account created successfully',
          user: { id, name, email, role, phone },
          token,
        });
      }
    );
  } catch (error) {
    handleDbError(res, 'Server error', error);
  }
});

// Login
app.post('/api/login', authLimiter, validate([
  body('email').isEmail().normalizeEmail().withMessage('A valid email is required'),
  body('password').isLength({ min: 1 }).withMessage('Password is required'),
]), async (req, res) => {
  const { email, password } = req.body;

  db.get('SELECT * FROM users WHERE email = ?', [email], async (err, user) => {
    if (err) {
      return handleDbError(res, 'Database error', err);
    }
    if (!user) {
      return res.status(401).json({ message: 'Invalid email or password' });
    }

    try {
      const isMatch = await bcrypt.compare(password, user.password);
      if (!isMatch) {
        return res.status(401).json({ message: 'Invalid email or password' });
      }

      const token = jwt.sign(
        { sub: user.id, email: user.email, role: user.role, jti: crypto.randomUUID() },
        JWT_SECRET,
        { expiresIn: '2h', algorithm: 'HS256' }
      );

      res.json({
        message: 'Login successful',
        user: {
          id: user.id,
          name: user.name,
          email: user.email,
          role: user.role,
          phone: user.phone,
          verified: user.verified === 1,
          bio: user.bio,
        },
        token,
      });
    } catch (error) {
      handleDbError(res, 'Server error', error);
    }
  });
});

// ========== TIPS ROUTES ==========

// Get all tips (with optional filters)
// Public users see non-premium tips with limited info; authenticated users get more
app.get('/api/tips', (req, res) => {
  const { is_premium, tipster_id, status, page = 1, limit = 50 } = req.query;
  const pageNum = Math.max(1, parseInt(page, 10) || 1);
  const limitNum = Math.min(100, Math.max(1, parseInt(limit, 10) || 50));
  const offset = (pageNum - 1) * limitNum;

  // Extract auth info if present
  let currentUserId = null;
  let currentUserRole = null;
  const token = req.headers.authorization?.split(' ')[1];
  if (token) {
    try {
      const decoded = jwt.verify(token, JWT_SECRET, { algorithms: ['HS256'] });
      currentUserId = decoded.sub;
      currentUserRole = decoded.role;
    } catch (e) {
      // Ignore invalid token
    }
  }

  let query = `
    SELECT t.*, u.name as tipster_name, u.verified as tipster_verified,
           (SELECT COUNT(*) FROM booking_codes WHERE tip_id = t.id) as booking_code_count
    FROM tips t
    JOIN users u ON t.tipster_id = u.id
    WHERE 1=1
  `;
  const params = [];

  if (is_premium !== undefined) {
    query += ' AND t.is_premium = ?';
    params.push(is_premium === 'true' ? 1 : 0);
  }
  if (tipster_id) {
    query += ' AND t.tipster_id = ?';
    params.push(tipster_id);
  }
  if (status) {
    query += ' AND t.status = ?';
    params.push(status);
  }

  query += ' ORDER BY t.created_at DESC LIMIT ? OFFSET ?';
  params.push(limitNum, offset);

  db.all(query, params, (err, tips) => {
    if (err) {
      return handleDbError(res, 'Error fetching tips', err);
    }

    if (tips.length === 0) {
      return res.json({ tips: [] });
    }

    // Get user purchases if logged in
    const getPurchasesPromise = new Promise((resolve) => {
      if (!currentUserId) return resolve([]);
      db.all('SELECT tip_id FROM purchases WHERE user_id = ?', [currentUserId], (err, rows) => {
        if (err) return resolve([]);
        resolve(rows.map(r => r.tip_id));
      });
    });

    getPurchasesPromise.then((purchasedTipIds) => {
      const tipIds = tips.map(t => t.id);
      const placeholders = tipIds.map(() => '?').join(',');
      db.all(`SELECT * FROM booking_codes WHERE tip_id IN (${placeholders})`, tipIds, (err, codes) => {
        if (err) {
          return handleDbError(res, 'Error fetching booking codes', err);
        }

        const tipsWithCodes = tips.map(tip => {
          const tipData = {
            ...tip,
            is_premium: tip.is_premium === 1,
            tipster_verified: tip.tipster_verified === 1,
            booking_code_count: tip.booking_code_count,
          };
          
          const isCreator = currentUserId && tip.tipster_id === currentUserId;
          const isAdmin = currentUserRole === 'admin';
          const hasPurchased = purchasedTipIds.includes(tip.id);

          if (!tipData.is_premium || isCreator || isAdmin || hasPurchased) {
            tipData.booking_codes = codes.filter(c => c.tip_id === tip.id);
          }
          return tipData;
        });

        res.json({ tips: tipsWithCodes, page: pageNum, limit: limitNum });
      });
    });
  });
});

// Get single tip with booking codes
app.get('/api/tips/:id', validate([
  param('id').isUUID().withMessage('Invalid tip ID'),
]), (req, res) => {
  const { id } = req.params;

  // Extract auth info if present
  let currentUserId = null;
  let currentUserRole = null;
  const token = req.headers.authorization?.split(' ')[1];
  if (token) {
    try {
      const decoded = jwt.verify(token, JWT_SECRET, { algorithms: ['HS256'] });
      currentUserId = decoded.sub;
      currentUserRole = decoded.role;
    } catch (e) {
      // Ignore invalid token
    }
  }

  db.get(
    `SELECT t.*, u.name as tipster_name, u.verified as tipster_verified
     FROM tips t
     JOIN users u ON t.tipster_id = u.id
     WHERE t.id = ?`,
    [id],
    (err, tip) => {
      if (err) {
        return handleDbError(res, 'Error fetching tip', err);
      }
      if (!tip) {
        return res.status(404).json({ message: 'Tip not found' });
      }

      db.run('UPDATE tips SET views = views + 1 WHERE id = ?', [id]);

      // Check purchase
      const checkPurchasePromise = new Promise((resolve) => {
        if (!currentUserId) return resolve(false);
        db.get('SELECT id FROM purchases WHERE user_id = ? AND tip_id = ?', [currentUserId, id], (err, row) => {
          resolve(!!row);
        });
      });

      checkPurchasePromise.then((hasPurchased) => {
        db.all('SELECT * FROM booking_codes WHERE tip_id = ?', [id], (err, codes) => {
          if (err) {
            return handleDbError(res, 'Error fetching booking codes', err);
          }

          const tipData = {
            ...tip,
            is_premium: tip.is_premium === 1,
            tipster_verified: tip.tipster_verified === 1,
          };

          const isCreator = currentUserId && tipData.tipster_id === currentUserId;
          const isAdmin = currentUserRole === 'admin';

          if (!tipData.is_premium || isCreator || isAdmin || hasPurchased) {
            tipData.booking_codes = codes;
          }

          res.json({ tip: tipData });
        });
      });
    }
  );
});

// Create new tip (tipster only)
app.post('/api/tips', authMiddleware, validate([
  body('title').trim().isLength({ min: 3, max: 200 }).withMessage('Title must be 3-200 characters'),
  body('description').optional({ values: 'falsy' }).trim().isLength({ max: 5000 }),
  body('total_odds').isFloat({ min: 1.01, max: 100000 }).withMessage('Odds must be between 1.01 and 100000'),
  body('is_premium').optional().isBoolean().withMessage('is_premium must be a boolean'),
  body('custom_price').optional({ values: 'falsy' }).isFloat({ min: 0 }),
  body('booking_codes').optional().isArray(),
  body('booking_codes.*.company').isLength({ min: 1, max: 100 }),
  body('booking_codes.*.code').isLength({ min: 1, max: 200 }),
]), (req, res) => {
  const { title, description, total_odds, is_premium, custom_price, booking_codes } = req.body;
  const tipster_id = req.user.sub;

  if (req.user.role !== 'tipster' && req.user.role !== 'admin') {
    return res.status(403).json({ message: 'Only tipsters can create tips' });
  }

  const tipId = crypto.randomUUID();
  const premium = is_premium ? 1 : 0;

  db.run(
    `INSERT INTO tips (id, tipster_id, title, description, total_odds, is_premium, custom_price)
     VALUES (?, ?, ?, ?, ?, ?, ?)`,
    [tipId, tipster_id, title, description || null, total_odds, premium, custom_price || null],
    function(err) {
      if (err) {
        return handleDbError(res, 'Error creating tip', err);
      }

      if (booking_codes && booking_codes.length > 0) {
        const stmt = db.prepare('INSERT INTO booking_codes (id, tip_id, company, code) VALUES (?, ?, ?, ?)');
        booking_codes.forEach(bc => {
          stmt.run(crypto.randomUUID(), tipId, bc.company, bc.code);
        });
        stmt.finalize();
      }

      db.all('SELECT id FROM users WHERE id != ?', [tipster_id], (err, users) => {
        if (!err && users.length > 0) {
          const notifStmt = db.prepare(
            'INSERT INTO notifications (id, user_id, title, message, type) VALUES (?, ?, ?, ?, ?)'
          );
          users.forEach(user => {
            notifStmt.run(
              crypto.randomUUID(),
              user.id,
              premium ? 'New VIP Tip Available!' : 'New Free Tip Posted!',
              `A tipster posted: ${title} (${total_odds} odds)`,
              'new_tip'
            );
          });
          notifStmt.finalize();
        }
      });

      res.status(201).json({
        message: 'Tip created successfully',
        tip: { id: tipId, title, total_odds, is_premium: premium === 1 }
      });
    }
  );
});

// Update tip status (mark won/lost)
app.patch('/api/tips/:id/status', authMiddleware, validate([
  param('id').isUUID().withMessage('Invalid tip ID'),
  body('status').isIn(['pending', 'won', 'lost']).withMessage('Status must be pending, won, or lost'),
]), (req, res) => {
  const { id } = req.params;
  const { status } = req.body;

  db.get('SELECT tipster_id FROM tips WHERE id = ?', [id], (err, tip) => {
    if (err) {
      return handleDbError(res, 'Database error', err);
    }
    if (!tip) {
      return res.status(404).json({ message: 'Tip not found' });
    }
    if (tip.tipster_id !== req.user.sub && req.user.role !== 'admin') {
      return res.status(403).json({ message: 'Unauthorized' });
    }

    db.run('UPDATE tips SET status = ? WHERE id = ?', [status, id], function(err) {
      if (err) {
        return handleDbError(res, 'Error updating tip', err);
      }
      res.json({ message: 'Tip status updated', status });
    });
  });
});

// Delete tip
app.delete('/api/tips/:id', authMiddleware, validate([
  param('id').isUUID().withMessage('Invalid tip ID'),
]), (req, res) => {
  const { id } = req.params;

  db.get('SELECT tipster_id FROM tips WHERE id = ?', [id], (err, tip) => {
    if (err) {
      return handleDbError(res, 'Database error', err);
    }
    if (!tip) {
      return res.status(404).json({ message: 'Tip not found' });
    }
    if (tip.tipster_id !== req.user.sub && req.user.role !== 'admin') {
      return res.status(403).json({ message: 'Unauthorized' });
    }

    db.run('DELETE FROM tips WHERE id = ?', [id], function(err) {
      if (err) {
        return handleDbError(res, 'Error deleting tip', err);
      }
      res.json({ message: 'Tip deleted successfully' });
    });
  });
});

// ========== VERIFICATION REQUESTS ROUTES ==========

// Submit verification request
app.post('/api/verification-requests', authMiddleware, validate([
  body('full_name').trim().isLength({ min: 2, max: 200 }).withMessage('Full name is required'),
  body('phone').isMobilePhone('any').withMessage('Valid phone number is required'),
  body('email').isEmail().normalizeEmail().withMessage('Valid email is required'),
  body('experience').trim().isLength({ min: 2, max: 100 }).withMessage('Experience is required'),
  body('expertise').trim().isLength({ min: 2, max: 500 }).withMessage('Expertise is required'),
  body('social_links').optional({ values: 'falsy' }).trim(),
  body('why_verify').trim().isLength({ min: 10, max: 2000 }).withMessage('Reason is required'),
  body('id_image_url').isURL().withMessage('ID image URL is required'),
  body('selfie_image_url').isURL().withMessage('Selfie image URL is required'),
]), (req, res) => {
  const { full_name, phone, email, experience, expertise, social_links, why_verify, id_image_url, selfie_image_url } = req.body;
  const user_id = req.user.sub;

  if (req.user.role !== 'tipster') {
    return res.status(403).json({ message: 'Only tipsters can apply for verification' });
  }

  db.get('SELECT verified FROM users WHERE id = ?', [user_id], (err, user) => {
    if (err) {
      return handleDbError(res, 'Database error', err);
    }
    if (user.verified === 1) {
      return res.status(400).json({ message: 'You are already verified' });
    }

    db.get(
      'SELECT id FROM verification_requests WHERE user_id = ? AND status = ?',
      [user_id, 'pending'],
      (err, existing) => {
        if (err) {
          return handleDbError(res, 'Database error', err);
        }
        if (existing) {
          return res.status(409).json({ message: 'You already have a pending verification request' });
        }

        const requestId = crypto.randomUUID();

        db.run(
          `INSERT INTO verification_requests
           (id, user_id, full_name, phone, email, experience, expertise, social_links, why_verify, id_image_url, selfie_image_url)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
          [requestId, user_id, full_name, phone, email, experience, expertise, social_links || null, why_verify, id_image_url, selfie_image_url],
          function(err) {
            if (err) {
              return handleDbError(res, 'Error creating verification request', err);
            }

            res.status(201).json({
              message: 'Verification request submitted successfully',
              request: { id: requestId, status: 'pending' }
            });
          }
        );
      }
    );
  });
});

// Get all verification requests (admin only)
app.get('/api/verification-requests', authMiddleware, (req, res) => {
  if (req.user.role !== 'admin') {
    return res.status(403).json({ message: 'Admin access required' });
  }

  const { status } = req.query;
  let query = `
    SELECT vr.*, u.name as user_name, u.email as user_email
    FROM verification_requests vr
    JOIN users u ON vr.user_id = u.id
    WHERE 1=1
  `;
  const params = [];

  if (status && ['pending', 'approved', 'rejected'].includes(status)) {
    query += ' AND vr.status = ?';
    params.push(status);
  }

  query += ' ORDER BY vr.applied_date DESC';

  db.all(query, params, (err, requests) => {
    if (err) {
      return handleDbError(res, 'Error fetching requests', err);
    }
    res.json({ requests });
  });
});

// Get my verification request
app.get('/api/verification-requests/my', authMiddleware, (req, res) => {
  db.all(
    `SELECT * FROM verification_requests WHERE user_id = ? ORDER BY applied_date DESC`,
    [req.user.sub],
    (err, requests) => {
      if (err) {
        return handleDbError(res, 'Error fetching requests', err);
      }
      res.json({ requests });
    }
  );
});

// Update verification request status (admin only)
app.patch('/api/verification-requests/:id/status', authMiddleware, validate([
  param('id').isUUID().withMessage('Invalid request ID'),
  body('status').isIn(['pending', 'approved', 'rejected']).withMessage('Invalid status'),
]), (req, res) => {
  const { id } = req.params;
  const { status } = req.body;

  if (req.user.role !== 'admin') {
    return res.status(403).json({ message: 'Admin access required' });
  }

  db.get('SELECT user_id FROM verification_requests WHERE id = ?', [id], (err, request) => {
    if (err) {
      return handleDbError(res, 'Database error', err);
    }
    if (!request) {
      return res.status(404).json({ message: 'Request not found' });
    }

    db.run(
      'UPDATE verification_requests SET status = ? WHERE id = ?',
      [status, id],
      function(err) {
        if (err) {
          return handleDbError(res, 'Error updating request', err);
        }

        if (status === 'approved') {
          db.run('UPDATE users SET verified = 1 WHERE id = ?', [request.user_id]);
        }

        res.json({ message: `Verification request ${status}` });
      }
    );
  });
});

// ========== TIPSTERS ROUTES ==========

// Get all tipsters
app.get('/api/tipsters', (req, res) => {
  const { verified } = req.query;

  let query = `
    SELECT
      u.id, u.name, u.bio, u.verified, u.followers_count,
      (SELECT COUNT(*) FROM tips WHERE tipster_id = u.id) as total_tips,
      (SELECT COUNT(*) FROM tips WHERE tipster_id = u.id AND status = 'won') as won_tips,
      (SELECT COUNT(*) FROM tips WHERE tipster_id = u.id AND status = 'lost') as lost_tips
    FROM users u
    WHERE u.role = 'tipster'
  `;
  const params = [];

  if (verified === 'true') {
    query += ' AND u.verified = 1';
  }

  query += ' ORDER BY u.verified DESC, u.followers_count DESC';

  db.all(query, params, (err, tipsters) => {
    if (err) {
      return handleDbError(res, 'Error fetching tipsters', err);
    }

    const tipstersWithStats = tipsters.map(t => {
      const total = t.total_tips || 0;
      const won = t.won_tips || 0;
      const winRate = total > 0 ? Math.round((won / total) * 100) : 0;

      return {
        ...t,
        verified: t.verified === 1,
        total_tips: total,
        won_tips: won,
        lost_tips: t.lost_tips || 0,
        win_rate: winRate
      };
    });

    res.json({ tipsters: tipstersWithStats });
  });
});

// Get tipster profile with recent tips
app.get('/api/tipsters/:id', (req, res) => {
  const { id } = req.params;

  db.get(
    `SELECT
      u.id, u.name, u.bio, u.verified, u.followers_count,
      (SELECT COUNT(*) FROM tips WHERE tipster_id = u.id) as total_tips,
      (SELECT COUNT(*) FROM tips WHERE tipster_id = u.id AND status = 'won') as won_tips,
      (SELECT COUNT(*) FROM tips WHERE tipster_id = u.id AND status = 'lost') as lost_tips
    FROM users u
    WHERE u.id = ? AND u.role = 'tipster'`,
    [id],
    (err, tipster) => {
      if (err) {
        return handleDbError(res, 'Error fetching tipster', err);
      }
      if (!tipster) {
        return res.status(404).json({ message: 'Tipster not found' });
      }

      const total = tipster.total_tips || 0;
      const won = tipster.won_tips || 0;
      const winRate = total > 0 ? Math.round((won / total) * 100) : 0;

      const tipsterWithStats = {
        ...tipster,
        verified: tipster.verified === 1,
        total_tips: total,
        won_tips: won,
        lost_tips: tipster.lost_tips || 0,
        win_rate: winRate
      };

      db.all(
        `SELECT id, title, description, total_odds, is_premium, status, created_at
         FROM tips
         WHERE tipster_id = ?
         ORDER BY created_at DESC
         LIMIT 10`,
        [id],
        (err, tips) => {
          if (err) {
            return handleDbError(res, 'Error fetching tips', err);
          }

          const isAuth = !!req.headers.authorization;
          const safeTips = tips.map(t => {
            const tipData = {
              id: t.id, title: t.title, total_odds: t.total_odds,
              status: t.status, created_at: t.created_at,
              is_premium: t.is_premium === 1,
            };
            if (!tipData.is_premium || isAuth) {
              tipData.description = t.description;
            }
            return tipData;
          });

          res.json({ tipster: { ...tipsterWithStats, recent_tips: safeTips } });
        }
      );
    }
  );
});

// ========== NOTIFICATIONS ROUTES ==========

// Get user notifications
app.get('/api/notifications', authMiddleware, (req, res) => {
  db.all(
    `SELECT * FROM notifications
     WHERE user_id = ? OR user_id IS NULL
     ORDER BY created_at DESC
     LIMIT 50`,
    [req.user.sub],
    (err, notifications) => {
      if (err) {
        return handleDbError(res, 'Error fetching notifications', err);
      }
      res.json({ notifications });
    }
  );
});

// Mark notification as read
app.patch('/api/notifications/:id/read', authMiddleware, validate([
  param('id').isUUID().withMessage('Invalid notification ID'),
]), (req, res) => {
  const { id } = req.params;

  db.run(
    'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?',
    [id, req.user.sub],
    function(err) {
      if (err) {
        return handleDbError(res, 'Error updating notification', err);
      }
      if (this.changes === 0) {
        return res.status(404).json({ message: 'Notification not found' });
      }
      res.json({ message: 'Notification marked as read' });
    }
  );
});

// Mark all notifications as read
app.post('/api/notifications/read-all', authMiddleware, (req, res) => {
  db.run(
    'UPDATE notifications SET is_read = 1 WHERE user_id = ?',
    [req.user.sub],
    function(err) {
      if (err) {
        return handleDbError(res, 'Error updating notifications', err);
      }
      res.json({ message: 'All notifications marked as read' });
    }
  );
});

// ========== USER ROUTES ==========

// Get user profile
app.get('/api/users/profile', authMiddleware, (req, res) => {
  db.get(
    `SELECT id, name, email, phone, role, verified, bio, avatar, followers_count, created_at
     FROM users WHERE id = ?`,
    [req.user.sub],
    (err, user) => {
      if (err) {
        return handleDbError(res, 'Error fetching profile', err);
      }
      if (!user) {
        return res.status(404).json({ message: 'User not found' });
      }

      res.json({
        user: {
          ...user,
          verified: user.verified === 1
        }
      });
    }
  );
});

// Update user profile
app.patch('/api/users/profile', authMiddleware, validate([
  body('name').optional().trim().isLength({ min: 2, max: 100 }).withMessage('Name must be 2-100 characters'),
  body('phone').optional({ values: 'falsy' }).matches(/^\+?[0-9]{7,15}$/).withMessage('Invalid phone number'),
  body('bio').optional({ values: 'falsy' }).trim().isLength({ max: 2000 }),
]), (req, res) => {
  const { name, phone, bio } = req.body;

  const allowedFields = ['name', 'phone', 'bio'];
  const fields = [];
  const values = [];

  if (name !== undefined && allowedFields.includes('name')) {
    fields.push('name = ?');
    values.push(name);
  }
  if (phone !== undefined && allowedFields.includes('phone')) {
    fields.push('phone = ?');
    values.push(phone);
  }
  if (bio !== undefined && allowedFields.includes('bio')) {
    fields.push('bio = ?');
    values.push(bio);
  }

  if (fields.length === 0) {
    return res.status(400).json({ message: 'No fields to update' });
  }

  values.push(req.user.sub);

  db.run(
    `UPDATE users SET ${fields.join(', ')} WHERE id = ?`,
    values,
    function(err) {
      if (err) {
        return handleDbError(res, 'Error updating profile', err);
      }
      res.json({ message: 'Profile updated successfully' });
    }
  );
});

// ========== PURCHASES / TIPS HISTORY ROUTES ==========

// Create a FastLipa transaction server-side so the mobile app never exposes the auth token.
app.post('/api/payments/fastlipa/create-transaction', paymentLimiter, validate([
  body('number').matches(/^[0-9]{9,15}$/).withMessage('Valid phone number is required'),
  body('amount').isFloat({ min: 1 }).withMessage('Valid amount is required'),
  body('name').optional({ values: 'falsy' }).trim().isLength({ max: 100 }),
]), async (req, res) => {
  if (!FASTLIPA_AUTH_TOKEN) {
    return res.status(503).json({ message: 'Payment service is not configured' });
  }

  const { number, amount, name } = req.body;

  try {
    const response = await fetch(`${FASTLIPA_API_URL}/create-transaction`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${FASTLIPA_AUTH_TOKEN}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        number,
        amount,
        name: name || 'Connection Client',
      }),
    });

    const data = await parseJsonSafely(response);

    if (!response.ok) {
      return res.status(400).json({
        message: 'Payment initiation failed. Please try again later.',
      });
    }

    res.json(data);
  } catch (error) {
    console.error('FastLipa create error:', error.message);
    res.status(502).json({
      message: 'Payment service is temporarily unavailable. Please try again later.',
    });
  }
});

app.get('/api/payments/fastlipa/status/:tranid', paymentLimiter, validate([
  param('tranid').isLength({ min: 1, max: 200 }).withMessage('Transaction ID is required'),
]), async (req, res) => {
  if (!FASTLIPA_AUTH_TOKEN) {
    return res.status(503).json({ message: 'Payment service is not configured' });
  }

  const { tranid } = req.params;

  try {
    const response = await fetch(
      `${FASTLIPA_API_URL}/status-transaction?tranid=${encodeURIComponent(tranid)}`,
      {
        headers: {
          Authorization: `Bearer ${FASTLIPA_AUTH_TOKEN}`,
        },
      }
    );

    const data = await parseJsonSafely(response);

    if (!response.ok) {
      return res.status(400).json({
        message: 'Unable to verify payment status. Please try again later.',
      });
    }

    res.json(data);
  } catch (error) {
    console.error('FastLipa status error:', error.message);
    res.status(502).json({
      message: 'Payment service is temporarily unavailable. Please try again later.',
    });
  }
});

// Record a new purchase
app.post('/api/purchases', authMiddleware, validate([
  body('tip_id').isUUID().withMessage('Valid tip ID is required'),
  body('amount').isFloat({ min: 0.01 }).withMessage('Valid amount is required'),
  body('payment_method').optional({ values: 'falsy' }).isLength({ max: 50 }),
  body('transaction_id').optional({ values: 'falsy' }).isLength({ max: 200 }),
]), (req, res) => {
  const { tip_id, amount, payment_method, transaction_id } = req.body;
  const user_id = req.user.sub;

  db.get(
    'SELECT id FROM purchases WHERE user_id = ? AND tip_id = ?',
    [user_id, tip_id],
    (err, existing) => {
      if (err) {
        return handleDbError(res, 'Database error', err);
      }
      if (existing) {
        return res.status(409).json({ message: 'You have already purchased this tip' });
      }

      const purchaseId = crypto.randomUUID();

      db.run(
        `INSERT INTO purchases (id, user_id, tip_id, amount, payment_method, transaction_id)
         VALUES (?, ?, ?, ?, ?, ?)`,
        [purchaseId, user_id, tip_id, amount, payment_method || 'mpesa', transaction_id],
        function(err) {
          if (err) {
            return handleDbError(res, 'Error recording purchase', err);
          }

          res.status(201).json({
            message: 'Purchase recorded successfully',
            purchase: { id: purchaseId, tip_id, amount }
          });
        }
      );
    }
  );
});

// Get user's purchase history with tip details
app.get('/api/purchases/my', authMiddleware, (req, res) => {
  const user_id = req.user.sub;

  db.all(
    `SELECT
      p.id as purchase_id,
      p.amount,
      p.payment_method,
      p.transaction_id,
      p.purchased_at,
      t.id as tip_id,
      t.title,
      t.description,
      t.total_odds,
      t.status as tip_status,
      u.name as tipster_name,
      u.verified as tipster_verified
    FROM purchases p
    JOIN tips t ON p.tip_id = t.id
    JOIN users u ON t.tipster_id = u.id
    WHERE p.user_id = ?
    ORDER BY p.purchased_at DESC`,
    [user_id],
    (err, purchases) => {
      if (err) {
        return handleDbError(res, 'Error fetching purchases', err);
      }

      const tipIds = purchases.map(p => p.tip_id);
      if (tipIds.length === 0) {
        return res.json({ purchases: [] });
      }

      const placeholders = tipIds.map(() => '?').join(',');
      db.all(
        `SELECT * FROM booking_codes WHERE tip_id IN (${placeholders})`,
        tipIds,
        (err, codes) => {
          if (err) {
            return handleDbError(res, 'Error fetching booking codes', err);
          }

          const purchasesWithCodes = purchases.map(purchase => ({
            ...purchase,
            tipster_verified: purchase.tipster_verified === 1,
            booking_codes: codes.filter(c => c.tip_id === purchase.tip_id)
          }));

          res.json({ purchases: purchasesWithCodes });
        }
      );
    }
  );
});

// Check if user has purchased a specific tip
app.get('/api/purchases/check/:tip_id', authMiddleware, validate([
  param('tip_id').isUUID().withMessage('Valid tip ID is required'),
]), (req, res) => {
  const { tip_id } = req.params;
  const user_id = req.user.sub;

  db.get(
    'SELECT * FROM purchases WHERE user_id = ? AND tip_id = ?',
    [user_id, tip_id],
    (err, purchase) => {
      if (err) {
        return handleDbError(res, 'Database error', err);
      }
      res.json({ hasPurchased: !!purchase, purchase });
    }
  );
});

// Get all purchases (admin only)
app.get('/api/purchases', authMiddleware, (req, res) => {
  if (req.user.role !== 'admin') {
    return res.status(403).json({ message: 'Admin access required' });
  }

  db.all(
    `SELECT
      p.*,
      u.name as user_name,
      u.email as user_email,
      t.title as tip_title
    FROM purchases p
    JOIN users u ON p.user_id = u.id
    JOIN tips t ON p.tip_id = t.id
    ORDER BY p.purchased_at DESC`,
    [],
    (err, purchases) => {
      if (err) {
        return handleDbError(res, 'Error fetching purchases', err);
      }
      res.json({ purchases });
    }
  );
});

// ========== ADDED EXTENSION ROUTES ==========

// Change Password
app.post('/api/users/change-password', authMiddleware, validate([
  body('current_password').isLength({ min: 1 }).withMessage('Current password is required'),
  body('new_password')
    .isLength({ min: 8, max: 128 }).withMessage('Password must be at least 8 characters')
    .matches(/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/).withMessage('Password must contain uppercase, lowercase, and a number'),
]), async (req, res) => {
  const { current_password, new_password } = req.body;
  const userId = req.user.sub;

  db.get('SELECT password FROM users WHERE id = ?', [userId], async (err, user) => {
    if (err) return handleDbError(res, 'Database error', err);
    if (!user) return res.status(404).json({ message: 'User not found' });

    try {
      const isMatch = await bcrypt.compare(current_password, user.password);
      if (!isMatch) {
        return res.status(400).json({ message: 'Incorrect current password' });
      }

      const hashedPassword = await bcrypt.hash(new_password, BCRYPT_ROUNDS);
      db.run('UPDATE users SET password = ? WHERE id = ?', [hashedPassword, userId], function(err) {
        if (err) return handleDbError(res, 'Error updating password', err);
        res.json({ message: 'Password changed successfully' });
      });
    } catch (error) {
      handleDbError(res, 'Server error', error);
    }
  });
});

// Delete Account
app.post('/api/users/delete', authMiddleware, validate([
  body('password').isLength({ min: 1 }).withMessage('Password is required'),
]), async (req, res) => {
  const { password } = req.body;
  const userId = req.user.sub;

  db.get('SELECT password FROM users WHERE id = ?', [userId], async (err, user) => {
    if (err) return handleDbError(res, 'Database error', err);
    if (!user) return res.status(404).json({ message: 'User not found' });

    try {
      const isMatch = await bcrypt.compare(password, user.password);
      if (!isMatch) {
        return res.status(400).json({ message: 'Incorrect password' });
      }

      db.serialize(() => {
        db.run('DELETE FROM followers WHERE follower_id = ? OR tipster_id = ?', [userId, userId]);
        db.run('DELETE FROM notifications WHERE user_id = ?', [userId]);
        db.run('DELETE FROM verification_requests WHERE user_id = ?', [userId]);
        db.run('DELETE FROM purchases WHERE user_id = ?', [userId]);
        db.run('DELETE FROM booking_codes WHERE tip_id IN (SELECT id FROM tips WHERE tipster_id = ?)', [userId]);
        db.run('DELETE FROM tips WHERE tipster_id = ?', [userId]);
        db.run('DELETE FROM users WHERE id = ?', [userId], function(err) {
          if (err) return handleDbError(res, 'Error deleting user', err);
          res.json({ message: 'Account deleted successfully' });
        });
      });
    } catch (error) {
      handleDbError(res, 'Server error', error);
    }
  });
});

// Broadcast Notification
app.post('/api/notifications/broadcast', authMiddleware, validate([
  body('title').trim().isLength({ min: 1, max: 200 }).withMessage('Title is required'),
  body('body').trim().isLength({ min: 1, max: 1000 }).withMessage('Body is required'),
  body('type').optional().isLength({ max: 50 }),
]), (req, res) => {
  const { title, body: msgBody, type } = req.body;
  const id = crypto.randomUUID();

  db.run(
    'INSERT INTO notifications (id, user_id, title, message, type) VALUES (?, NULL, ?, ?, ?)',
    [id, title, msgBody, type || 'broadcast'],
    function(err) {
      if (err) return handleDbError(res, 'Error broadcasting notification', err);
      res.status(201).json({ message: 'Notification broadcasted successfully', id });
    }
  );
});

// Get Tipster's My Tips
app.get('/api/my-tips', authMiddleware, (req, res) => {
  const { status } = req.query;
  let query = `
    SELECT t.*,
           (SELECT COUNT(*) FROM booking_codes WHERE tip_id = t.id) as booking_code_count
    FROM tips t
    WHERE t.tipster_id = ?
  `;
  const params = [req.user.sub];
  if (status && status !== 'all') {
    query += ' AND t.status = ?';
    params.push(status);
  }
  query += ' ORDER BY t.created_at DESC';
  db.all(query, params, (err, tips) => {
    if (err) return handleDbError(res, 'Error fetching my tips', err);
    if (tips.length === 0) return res.json({ tips: [] });
    const tipIds = tips.map(t => t.id);
    const placeholders = tipIds.map(() => '?').join(',');
    db.all(`SELECT * FROM booking_codes WHERE tip_id IN (${placeholders})`, tipIds, (err, codes) => {
      if (err) return handleDbError(res, 'Error fetching booking codes', err);
      const tipsWithCodes = tips.map(tip => ({
        ...tip,
        is_premium: tip.is_premium === 1,
        booking_codes: codes.filter(c => c.tip_id === tip.id)
      }));
      res.json({ tips: tipsWithCodes });
    });
  });
});

// Follow a tipster
app.post('/api/tipsters/:id/follow', authMiddleware, validate([
  param('id').isUUID().withMessage('Invalid tipster ID'),
]), (req, res) => {
  const tipsterId = req.params.id;
  const followerId = req.user.sub;

  if (tipsterId === followerId) {
    return res.status(400).json({ message: 'You cannot follow yourself' });
  }

  db.get('SELECT role FROM users WHERE id = ?', [tipsterId], (err, user) => {
    if (err) return handleDbError(res, 'Database error', err);
    if (!user || user.role !== 'tipster') {
      return res.status(404).json({ message: 'Tipster not found' });
    }

    const id = crypto.randomUUID();
    db.run(
      'INSERT INTO followers (id, follower_id, tipster_id) VALUES (?, ?, ?)',
      [id, followerId, tipsterId],
      function(err) {
        if (err) {
          if (err.message && err.message.includes('UNIQUE constraint failed')) {
            return res.status(409).json({ message: 'You are already following this tipster' });
          }
          return handleDbError(res, 'Error following tipster', err);
        }

        // Increment followers_count
        db.run('UPDATE users SET followers_count = followers_count + 1 WHERE id = ?', [tipsterId]);
        res.json({ message: 'Successfully followed tipster' });
      }
    );
  });
});

// Unfollow a tipster
app.post('/api/tipsters/:id/unfollow', authMiddleware, validate([
  param('id').isUUID().withMessage('Invalid tipster ID'),
]), (req, res) => {
  const tipsterId = req.params.id;
  const followerId = req.user.sub;

  db.run(
    'DELETE FROM followers WHERE follower_id = ? AND tipster_id = ?',
    [followerId, tipsterId],
    function(err) {
      if (err) return handleDbError(res, 'Error unfollowing tipster', err);
      if (this.changes === 0) {
        return res.status(404).json({ message: 'You were not following this tipster' });
      }

      // Decrement followers_count
      db.run('UPDATE users SET followers_count = MAX(0, followers_count - 1) WHERE id = ?', [tipsterId]);
      res.json({ message: 'Successfully unfollowed tipster' });
    }
  );
});

// Get followed tipster IDs
app.get('/api/tipsters/my/following', authMiddleware, (req, res) => {
  db.all(
    'SELECT tipster_id FROM followers WHERE follower_id = ?',
    [req.user.sub],
    (err, rows) => {
      if (err) return handleDbError(res, 'Error fetching followed tipsters', err);
      const followingIds = rows.map(r => r.tipster_id);
      res.json({ following: followingIds });
    }
  );
});

// Start server
app.listen(PORT, () => {
  console.log(`Server running on port ${PORT}`);
  console.log(`API available at http://localhost:${PORT}/api`);
});

module.exports = app;
