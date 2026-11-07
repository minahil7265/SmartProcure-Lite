<?php
// /var/www/smartprocure/includes/constants.php
//
// All roles and statuses in one place. Pages use these constants
// instead of typing the strings again and again. The values match
// the ENUM columns in database/schema.sql.

// ---- User roles --------------------------------------------------
const ROLE_EMPLOYEE    = 'employee';
const ROLE_MANAGER     = 'manager';
const ROLE_PROCUREMENT = 'procurement';
const ROLE_ADMIN       = 'admin';
const ROLE_VENDOR      = 'vendor';   // external supplier, sees only its own RFQs and orders

// Role used for every new signup (same as the database default).
const DEFAULT_ROLE = ROLE_EMPLOYEE;

const ROLE_LABELS = [
    ROLE_EMPLOYEE    => 'Employee / Requester',
    ROLE_MANAGER     => 'Manager / Approver',
    ROLE_PROCUREMENT => 'Procurement Officer',
    ROLE_ADMIN       => 'Administrator',
    ROLE_VENDOR      => 'Vendor',
];

// Staff of the organisation (everyone except vendors).
const STAFF_ROLES = [ROLE_EMPLOYEE, ROLE_MANAGER, ROLE_PROCUREMENT, ROLE_ADMIN];

// Roles that may raise purchase requests.
const REQUESTER_ROLES = [ROLE_EMPLOYEE, ROLE_MANAGER, ROLE_PROCUREMENT];

// ---- Purchase request statuses -----------------------------------
const REQ_DRAFT     = 'Draft';
const REQ_SUBMITTED = 'Submitted';
const REQ_APPROVED  = 'Approved';
const REQ_REJECTED  = 'Rejected';
const REQ_CANCELLED = 'Cancelled';

const REQUEST_STATUSES = [REQ_DRAFT, REQ_SUBMITTED, REQ_APPROVED, REQ_REJECTED, REQ_CANCELLED];

// Statuses in which the requester may still edit or cancel.
const REQUEST_EDITABLE = [REQ_DRAFT, REQ_SUBMITTED];

// ---- Approval decisions ------------------------------------------
const DECISION_APPROVED = 'Approved';
const DECISION_REJECTED = 'Rejected';

// ---- Vendor statuses ---------------------------------------------
const VENDOR_ACTIVE   = 'Active';
const VENDOR_INACTIVE = 'Inactive';
const VENDOR_STATUSES = [VENDOR_ACTIVE, VENDOR_INACTIVE];

// ---- Quotation statuses ------------------------------------------
const QUOTE_RECEIVED = 'Received';
const QUOTE_SELECTED = 'Selected';
const QUOTE_REJECTED = 'Rejected';

// ---- RFQ statuses ------------------------------------------------
// "Open" RFQs accept quotations until the deadline; after the deadline
// they are shown as "Closed" (worked out from the date, not stored).
const RFQ_OPEN      = 'Open';
const RFQ_AWARDED   = 'Awarded';
const RFQ_CANCELLED = 'Cancelled';

// ---- Purchase order statuses -------------------------------------
const PO_CREATED   = 'Created';        // sent, waiting for the vendor
const PO_ACCEPTED  = 'Accepted';       // vendor accepted
const PO_DECLINED  = 'Declined';       // vendor declined (budget released)
const PO_PARTIAL   = 'Partially Delivered';
const PO_DELIVERED = 'Delivered';
const PO_CANCELLED = 'Cancelled';

const PO_STATUSES = [PO_CREATED, PO_ACCEPTED, PO_DECLINED, PO_PARTIAL, PO_DELIVERED, PO_CANCELLED];

// Orders that can still receive goods (vendor has accepted).
const PO_OPEN = [PO_ACCEPTED, PO_PARTIAL];

// Orders that still hold budget and block a new order for the request.
const PO_ACTIVE = [PO_CREATED, PO_ACCEPTED, PO_PARTIAL, PO_DELIVERED];

// Orders that are still expected to arrive (used for "overdue").
const PO_PENDING_DELIVERY = [PO_CREATED, PO_ACCEPTED, PO_PARTIAL];

// ---- Delivery statuses -------------------------------------------
const DELIVERY_PARTIAL  = 'Partial';
const DELIVERY_COMPLETE = 'Complete';

// ---- Ratings -------------------------------------------------------
// Delivery-reliability ratings run from 1 to 5. The database checks
// the same range (quotations.quality_rating), so this is fixed.
const RATING_MIN = 1;
const RATING_MAX = 5;
