-- Generated SQL: AR invoices upsert from sales-entry CSV
-- Source file: /Users/mohamadsafar/Desktop/Layla Kitchen/RMS-1/docs/csv/Sales_entry_dailyreport_2026-03-01_07_52PM.csv
-- Generated at: 2026-03-06T12:27:55
-- Date range (inclusive): 2026-02-16 to 2026-03-01
-- Branch ID: 1
-- Total CSV rows: 311
-- Filtered rows in range: 311
-- Filtered min date: 2026-02-16
-- Filtered max date: 2026-02-28
-- Distinct documents in range: 311
-- Distinct non-empty POS refs in range: 0
-- Distinct normalized customers in range: 113
-- Matching rules: invoice by (branch, invoice_number) then (branch, pos_reference); customer by normalized name
-- Rerunnable behavior: upsert invoice headers + replace items for touched invoices only

START TRANSACTION;

SET @inserted_customers := 0;
SET @inserted_invoice_rows := 0;
SET @updated_invoice_rows := 0;
SET @deleted_invoice_item_rows := 0;
SET @inserted_invoice_item_rows := 0;

DROP TEMPORARY TABLE IF EXISTS tmp_sales_source;
CREATE TEMPORARY TABLE tmp_sales_source (
  source_row_num INT NOT NULL,
  warehouse VARCHAR(100) NOT NULL,
  source_timestamp VARCHAR(40) NOT NULL,
  business_date DATE NOT NULL,
  document_no VARCHAR(64) NOT NULL,
  customer_name VARCHAR(191) NOT NULL,
  customer_norm VARCHAR(191) NOT NULL COLLATE utf8mb4_unicode_ci,
  pos_reference VARCHAR(191) DEFAULT NULL,
  subtotal_cents BIGINT NOT NULL,
  discount_cents BIGINT NOT NULL,
  total_cents BIGINT NOT NULL,
  cash_cents BIGINT NOT NULL,
  card_cents BIGINT NOT NULL,
  credit_cents BIGINT NOT NULL,
  payment_type VARCHAR(20) NOT NULL,
  status VARCHAR(20) NOT NULL,
  paid_total_cents BIGINT NOT NULL,
  balance_cents BIGINT NOT NULL,
  PRIMARY KEY (source_row_num),
  UNIQUE KEY uq_tmp_sales_source_document_no (document_no),
  UNIQUE KEY uq_tmp_sales_source_pos_reference (pos_reference),
  KEY idx_tmp_sales_source_customer_norm (customer_norm),
  KEY idx_tmp_sales_source_business_date (business_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_sales_source (source_row_num, warehouse, source_timestamp, business_date, document_no, customer_name, customer_norm, pos_reference, subtotal_cents, discount_cents, total_cents, cash_cents, card_cents, credit_cents, payment_type, status, paid_total_cents, balance_cents) VALUES
(2, 'Branch 1', '2026-02-16T12:38:11.263000+03:00', '2026-02-16', 'INV7460', 'St Georges And Isaac Church', 'st georges and isaac church', NULL, 7000, 0, 7000, 0, 0, 7000, 'credit', 'issued', 0, 7000),
(3, 'Branch 1', '2026-02-16T12:39:01.480000+03:00', '2026-02-16', 'INV7461', 'GHADA MAALOUF', 'ghada maalouf', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(4, 'Branch 1', '2026-02-16T12:39:11.386000+03:00', '2026-02-16', 'INV7462', 'Fouad Abdelbaki', 'fouad abdelbaki', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(5, 'Branch 1', '2026-02-16T12:39:41.560000+03:00', '2026-02-16', 'INV7463', 'Eliane Daccache', 'eliane daccache', NULL, 13000, 0, 13000, 0, 0, 13000, 'credit', 'issued', 0, 13000),
(6, 'Branch 1', '2026-02-16T12:40:19.991000+03:00', '2026-02-16', 'INV7464', 'Fadi El Jam', 'fadi el jam', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(7, 'Branch 1', '2026-02-16T12:40:28.612000+03:00', '2026-02-16', 'INV7465', 'jackie', 'jackie', NULL, 16500, 0, 16500, 0, 0, 16500, 'credit', 'issued', 0, 16500),
(8, 'Branch 1', '2026-02-16T12:40:42.785000+03:00', '2026-02-16', 'INV7466', 'Antonio', 'antonio', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(9, 'Branch 1', '2026-02-16T12:40:50.561000+03:00', '2026-02-16', 'INV7467', 'Roger Abou Malhab', 'roger abou malhab', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(10, 'Branch 1', '2026-02-16T12:40:59.793000+03:00', '2026-02-16', 'INV7468', 'chady', 'chady', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(11, 'Branch 1', '2026-02-16T12:41:18.376000+03:00', '2026-02-16', 'INV7469', 'Mitche Maroun', 'mitche maroun', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(12, 'Branch 1', '2026-02-16T12:41:27.565000+03:00', '2026-02-16', 'INV7470', 'MARK Chidiac', 'mark chidiac', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(13, 'Branch 1', '2026-02-16T12:41:40.390000+03:00', '2026-02-16', 'INV7471', 'Ahmed Hafez', 'ahmed hafez', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(14, 'Branch 1', '2026-02-16T12:41:55.484000+03:00', '2026-02-16', 'INV7472', 'VICKY', 'vicky', NULL, 5000, 0, 5000, 0, 0, 5000, 'credit', 'issued', 0, 5000),
(15, 'Branch 1', '2026-02-16T12:42:03.247000+03:00', '2026-02-16', 'INV7473', 'Saeed Zeidan', 'saeed zeidan', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(16, 'Branch 1', '2026-02-16T12:42:10.826000+03:00', '2026-02-16', 'INV7474', 'Henry Sayegh', 'henry sayegh', NULL, 6500, 0, 6500, 0, 0, 6500, 'credit', 'issued', 0, 6500),
(17, 'Branch 1', '2026-02-16T12:42:23.382000+03:00', '2026-02-16', 'INV7475', 'Wael Fattouh', 'wael fattouh', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(18, 'Branch 1', '2026-02-16T12:42:30.931000+03:00', '2026-02-16', 'INV7476', 'Melody R', 'melody r', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(19, 'Branch 1', '2026-02-16T12:42:41.423000+03:00', '2026-02-16', 'INV7477', 'Youssef R', 'youssef r', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(20, 'Branch 1', '2026-02-16T12:42:58.032000+03:00', '2026-02-16', 'INV7478', 'Nour Khoury', 'nour khoury', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(21, 'Branch 1', '2026-02-16T12:43:05.650000+03:00', '2026-02-16', 'INV7479', 'PIA', 'pia', NULL, 21500, 0, 21500, 0, 0, 21500, 'credit', 'issued', 0, 21500),
(22, 'Branch 1', '2026-02-16T12:44:07.093000+03:00', '2026-02-16', 'INV7480', 'Nay Azzam', 'nay azzam', NULL, 21500, 0, 21500, 0, 0, 21500, 'credit', 'issued', 0, 21500),
(23, 'Branch 1', '2026-02-16T12:46:34.374000+03:00', '2026-02-16', 'INV7481', 'Ishrakh Jaradat', 'ishrakh jaradat', NULL, 27000, 0, 27000, 0, 0, 27000, 'credit', 'issued', 0, 27000),
(24, 'Branch 1', '2026-02-16T13:49:00+03:00', '2026-02-16', '100655V2', 'GAT Middle East', 'gat middle east', NULL, 10924000, 0, 10924000, 0, 0, 10924000, 'credit', 'issued', 0, 10924000),
(25, 'Branch 1', '2026-02-16T16:44:36.646000+03:00', '2026-02-16', 'INV7482', 'Sandy semaan', 'sandy semaan', NULL, 40000, 0, 40000, 0, 0, 40000, 'credit', 'issued', 0, 40000),
(26, 'Branch 1', '2026-02-16T16:45:22.921000+03:00', '2026-02-16', 'INV7483', 'Hala Attieh', 'hala attieh', NULL, 17000, 0, 17000, 0, 0, 17000, 'credit', 'issued', 0, 17000),
(27, 'Branch 1', '2026-02-16T16:45:55.076000+03:00', '2026-02-16', 'INV7484', 'Diana Hoteit', 'diana hoteit', NULL, 18000, 0, 18000, 18000, 0, 0, 'cash', 'paid', 18000, 0),
(28, 'Branch 1', '2026-02-16T16:46:58.737000+03:00', '2026-02-16', 'INV7485', 'Robert Sawaya', 'robert sawaya', NULL, 8500, 0, 8500, 0, 0, 8500, 'credit', 'issued', 0, 8500),
(29, 'Branch 1', '2026-02-17T11:02:22.175000+03:00', '2026-02-17', 'INV7486', 'DG JONES', 'dg jones', NULL, 302500, 0, 302500, 0, 0, 302500, 'credit', 'issued', 0, 302500),
(30, 'Branch 1', '2026-02-17T14:30:14+03:00', '2026-02-17', '100656', 'Funderdome', 'funderdome', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(31, 'Branch 1', '2026-02-17T14:58:24+03:00', '2026-02-17', '100680', 'St Georges And Isaac Church', 'st georges and isaac church', NULL, 7000, 0, 7000, 0, 0, 7000, 'credit', 'issued', 0, 7000),
(32, 'Branch 1', '2026-02-17T14:59:06+03:00', '2026-02-17', '100681', 'GHADA MAALOUF', 'ghada maalouf', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(33, 'Branch 1', '2026-02-17T14:59:37+03:00', '2026-02-17', '100682', 'Fouad Abdelbaki', 'fouad abdelbaki', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(34, 'Branch 1', '2026-02-17T15:00:35+03:00', '2026-02-17', '100683', 'Fadi El Jam', 'fadi el jam', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(35, 'Branch 1', '2026-02-17T15:01:09+03:00', '2026-02-17', '100684', 'jackie', 'jackie', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(36, 'Branch 1', '2026-02-17T15:01:40+03:00', '2026-02-17', '100685', 'Antonio', 'antonio', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(37, 'Branch 1', '2026-02-17T15:03:50+03:00', '2026-02-17', '100686', 'Roger Abou Malhab', 'roger abou malhab', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(38, 'Branch 1', '2026-02-17T15:04:27+03:00', '2026-02-17', '100687', 'Mitche Maroun', 'mitche maroun', NULL, 8000, 0, 8000, 0, 0, 8000, 'credit', 'issued', 0, 8000),
(39, 'Branch 1', '2026-02-17T15:05:03+03:00', '2026-02-17', '100688', 'MARK Chidiac', 'mark chidiac', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(40, 'Branch 1', '2026-02-17T15:05:35+03:00', '2026-02-17', '100689', 'Eliane Daccache', 'eliane daccache', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(41, 'Branch 1', '2026-02-17T15:06:11+03:00', '2026-02-17', '100690', 'VICKY', 'vicky', NULL, 5000, 0, 5000, 0, 0, 5000, 'credit', 'issued', 0, 5000),
(42, 'Branch 1', '2026-02-17T15:06:47+03:00', '2026-02-17', '100691', 'Saeed Zeidan', 'saeed zeidan', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(43, 'Branch 1', '2026-02-17T15:07:18+03:00', '2026-02-17', '100692', 'Wael Fattouh', 'wael fattouh', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(44, 'Branch 1', '2026-02-17T15:07:48+03:00', '2026-02-17', '100693', 'Nasri Rbeiz', 'nasri rbeiz', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(45, 'Branch 1', '2026-02-17T15:10:27+03:00', '2026-02-17', '100694', 'Nour Khoury', 'nour khoury', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(46, 'Branch 1', '2026-02-17T15:11:24+03:00', '2026-02-17', '100695', 'Eliana Salloum', 'eliana salloum', NULL, 8000, 0, 8000, 0, 0, 8000, 'credit', 'issued', 0, 8000),
(47, 'Branch 1', '2026-02-17T15:12:05+03:00', '2026-02-17', '100696', 'Jamil', 'jamil', NULL, 6500, 0, 6500, 0, 0, 6500, 'credit', 'issued', 0, 6500),
(48, 'Branch 1', '2026-02-17T15:13:02+03:00', '2026-02-17', '100697', 'PIA', 'pia', NULL, 21500, 0, 21500, 0, 0, 21500, 'credit', 'issued', 0, 21500),
(49, 'Branch 1', '2026-02-17T15:14:39+03:00', '2026-02-17', '100698', 'ABIR', 'abir', NULL, 18000, 0, 18000, 0, 0, 18000, 'credit', 'issued', 0, 18000),
(50, 'Branch 1', '2026-02-17T15:15:24+03:00', '2026-02-17', '100699', 'Mireille Khoury', 'mireille khoury', NULL, 30000, 0, 30000, 0, 0, 30000, 'credit', 'issued', 0, 30000),
(51, 'Branch 1', '2026-02-17T15:17:50+03:00', '2026-02-17', '100700', 'Dima Merhebi', 'dima merhebi', NULL, 9000, 0, 9000, 0, 0, 9000, 'credit', 'issued', 0, 9000),
(52, 'Branch 1', '2026-02-17T15:18:45+03:00', '2026-02-17', '100701', 'Joumana Chalhoub', 'joumana chalhoub', NULL, 10000, 0, 10000, 0, 0, 10000, 'credit', 'issued', 0, 10000),
(53, 'Branch 1', '2026-02-17T15:19:30+03:00', '2026-02-17', '100702', 'Amanda', 'amanda', NULL, 5000, 0, 5000, 0, 0, 5000, 'credit', 'issued', 0, 5000),
(54, 'Branch 1', '2026-02-17T15:20:06+03:00', '2026-02-17', '100703', 'Ghina Othman', 'ghina othman', NULL, 10000, 0, 10000, 0, 0, 10000, 'credit', 'issued', 0, 10000),
(55, 'Branch 1', '2026-02-18T12:38:30+03:00', '2026-02-18', '100657', 'St Georges And Isaac Church', 'st georges and isaac church', NULL, 7000, 0, 7000, 0, 0, 7000, 'credit', 'issued', 0, 7000),
(56, 'Branch 1', '2026-02-18T12:41:48+03:00', '2026-02-18', '100658', 'GHADA MAALOUF', 'ghada maalouf', NULL, 22000, 0, 22000, 0, 0, 22000, 'credit', 'issued', 0, 22000),
(57, 'Branch 1', '2026-02-18T12:43:13+03:00', '2026-02-18', '100659', 'Fouad Abdelbaki', 'fouad abdelbaki', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(58, 'Branch 1', '2026-02-18T12:44:21+03:00', '2026-02-18', '100660', 'Eliane Daccache', 'eliane daccache', NULL, 8000, 0, 8000, 0, 0, 8000, 'credit', 'issued', 0, 8000),
(59, 'Branch 1', '2026-02-18T12:46:20+03:00', '2026-02-18', '100661', 'Fadi El Jam', 'fadi el jam', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(60, 'Branch 1', '2026-02-18T12:47:50+03:00', '2026-02-18', '100662', 'jackie', 'jackie', NULL, 16500, 0, 16500, 0, 0, 16500, 'credit', 'issued', 0, 16500),
(61, 'Branch 1', '2026-02-18T12:50:51+03:00', '2026-02-18', '100664', 'Antonio', 'antonio', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(62, 'Branch 1', '2026-02-18T12:51:35+03:00', '2026-02-18', '100665V2', 'Mitche Maroun', 'mitche maroun', NULL, 8000, 0, 8000, 0, 0, 8000, 'credit', 'issued', 0, 8000),
(63, 'Branch 1', '2026-02-18T12:52:22+03:00', '2026-02-18', '100666V1', 'MARK Chidiac', 'mark chidiac', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(64, 'Branch 1', '2026-02-18T12:56:35+03:00', '2026-02-18', '100667', 'VICKY', 'vicky', NULL, 5000, 0, 5000, 0, 0, 5000, 'credit', 'issued', 0, 5000),
(65, 'Branch 1', '2026-02-18T13:00:34+03:00', '2026-02-18', '100668', 'Saeed Zeidan', 'saeed zeidan', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(66, 'Branch 1', '2026-02-18T13:01:51+03:00', '2026-02-18', '100669', 'Nour Khoury', 'nour khoury', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(67, 'Branch 1', '2026-02-18T13:02:39+03:00', '2026-02-18', '100670', 'PIA', 'pia', NULL, 21500, 0, 21500, 0, 0, 21500, 'credit', 'issued', 0, 21500),
(68, 'Branch 1', '2026-02-18T13:04:01+03:00', '2026-02-18', '100671', 'Rana N', 'rana n', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(69, 'Branch 1', '2026-02-18T13:06:15+03:00', '2026-02-18', '100672', 'chady', 'chady', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(70, 'Branch 1', '2026-02-18T13:07:51+03:00', '2026-02-18', '100673', 'DALAL', 'dalal', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(71, 'Branch 1', '2026-02-18T13:20:31+03:00', '2026-02-18', '100674', 'Maguy', 'maguy', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(72, 'Branch 1', '2026-02-18T13:21:09+03:00', '2026-02-18', '100675', 'Rawan Hachem', 'rawan hachem', NULL, 55500, 0, 55500, 0, 0, 55500, 'credit', 'issued', 0, 55500),
(73, 'Branch 1', '2026-02-18T14:46:33+03:00', '2026-02-18', '100676', 'Ghina Othman', 'ghina othman', NULL, 10000, 0, 10000, 0, 0, 10000, 'credit', 'issued', 0, 10000),
(74, 'Branch 1', '2026-02-18T14:47:42+03:00', '2026-02-18', '100677', 'Amanda', 'amanda', NULL, 18000, 0, 18000, 0, 0, 18000, 'credit', 'issued', 0, 18000),
(75, 'Branch 1', '2026-02-18T14:54:02+03:00', '2026-02-18', '100678', 'Yousef Khaled', 'yousef khaled', NULL, 19500, 0, 19500, 0, 0, 19500, 'credit', 'issued', 0, 19500),
(76, 'Branch 1', '2026-02-18T14:56:53+03:00', '2026-02-18', '100679', 'Rima Nahle', 'rima nahle', NULL, 4500, 0, 4500, 0, 0, 4500, 'credit', 'issued', 0, 4500),
(77, 'Branch 1', '2026-02-18T15:22:12+03:00', '2026-02-18', '100704', 'VICKY', 'vicky', NULL, 35000, 0, 35000, 0, 0, 35000, 'credit', 'issued', 0, 35000),
(78, 'Branch 1', '2026-02-18T15:23:51+03:00', '2026-02-18', '100705', 'Layla Al Helou', 'layla al helou', NULL, 24500, 0, 24500, 0, 0, 24500, 'credit', 'issued', 0, 24500),
(79, 'Branch 1', '2026-02-19T12:49:43+03:00', '2026-02-19', '100663', 'Roger Abou Malhab', 'roger abou malhab', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(80, 'Branch 1', '2026-02-19T15:27:28.477000+03:00', '2026-02-19', 'INV7487', 'St Georges And Isaac Church', 'st georges and isaac church', NULL, 7000, 0, 7000, 0, 0, 7000, 'credit', 'issued', 0, 7000),
(81, 'Branch 1', '2026-02-19T15:27:58.840000+03:00', '2026-02-19', 'INV7488', 'Fouad Abdelbaki', 'fouad abdelbaki', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(82, 'Branch 1', '2026-02-19T15:28:11.471000+03:00', '2026-02-19', 'INV7489', 'Fadi El Jam', 'fadi el jam', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(83, 'Branch 1', '2026-02-19T15:28:27.811000+03:00', '2026-02-19', 'INV7490', 'jackie', 'jackie', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(84, 'Branch 1', '2026-02-19T15:32:53.200000+03:00', '2026-02-19', 'INV7491', 'Antonio', 'antonio', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(85, 'Branch 1', '2026-02-19T15:33:01.089000+03:00', '2026-02-19', 'INV7492', 'Jihad Abou Chabke', 'jihad abou chabke', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(86, 'Branch 1', '2026-02-19T15:33:12.741000+03:00', '2026-02-19', 'INV7493', 'Roger Abou Malhab', 'roger abou malhab', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(87, 'Branch 1', '2026-02-19T15:33:23.195000+03:00', '2026-02-19', 'INV7494', 'Mitche Maroun', 'mitche maroun', NULL, 8000, 0, 8000, 0, 0, 8000, 'credit', 'issued', 0, 8000),
(88, 'Branch 1', '2026-02-19T15:33:33.750000+03:00', '2026-02-19', 'INV7495', 'MARK Chidiac', 'mark chidiac', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(89, 'Branch 1', '2026-02-19T15:33:48.153000+03:00', '2026-02-19', 'INV7496', 'Eliane Daccache', 'eliane daccache', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(90, 'Branch 1', '2026-02-19T15:33:56.805000+03:00', '2026-02-19', 'INV7497', 'VICKY', 'vicky', NULL, 5000, 0, 5000, 0, 0, 5000, 'credit', 'issued', 0, 5000),
(91, 'Branch 1', '2026-02-19T15:34:12.272000+03:00', '2026-02-19', 'INV7498', 'Saeed Zeidan', 'saeed zeidan', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(92, 'Branch 1', '2026-02-19T15:34:23.132000+03:00', '2026-02-19', 'INV7499', 'Nour Khoury', 'nour khoury', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(93, 'Branch 1', '2026-02-19T15:34:32.295000+03:00', '2026-02-19', 'INV7500', 'PIA', 'pia', NULL, 21500, 0, 21500, 0, 0, 21500, 'credit', 'issued', 0, 21500),
(94, 'Branch 1', '2026-02-19T15:34:56.036000+03:00', '2026-02-19', 'INV7501', 'Jamil', 'jamil', NULL, 6500, 0, 6500, 0, 0, 6500, 'credit', 'issued', 0, 6500),
(95, 'Branch 1', '2026-02-19T15:35:05.147000+03:00', '2026-02-19', 'INV7502', 'chady', 'chady', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(96, 'Branch 1', '2026-02-19T15:35:27.725000+03:00', '2026-02-19', 'INV7503', 'Ahmed Hafez', 'ahmed hafez', NULL, 25000, 0, 25000, 0, 0, 25000, 'credit', 'issued', 0, 25000),
(97, 'Branch 1', '2026-02-19T15:36:40.793000+03:00', '2026-02-19', 'INV7504', 'Rana N', 'rana n', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(98, 'Branch 1', '2026-02-19T15:36:52.487000+03:00', '2026-02-19', 'INV7505', 'Joumana Chalhoub', 'joumana chalhoub', NULL, 10000, 0, 10000, 0, 0, 10000, 'credit', 'issued', 0, 10000),
(99, 'Branch 1', '2026-02-19T15:37:06.075000+03:00', '2026-02-19', 'INV7506', 'alaa', 'alaa', NULL, 10000, 0, 10000, 0, 0, 10000, 'credit', 'issued', 0, 10000),
(100, 'Branch 1', '2026-02-19T15:37:22.858000+03:00', '2026-02-19', 'INV7507', 'Ghada El Rassi', 'ghada el rassi', NULL, 17000, 0, 17000, 0, 0, 17000, 'credit', 'issued', 0, 17000),
(101, 'Branch 1', '2026-02-19T15:37:59.423000+03:00', '2026-02-19', 'INV7508', 'Ghina Othman', 'ghina othman', NULL, 10000, 0, 10000, 0, 0, 10000, 'credit', 'issued', 0, 10000),
(102, 'Branch 1', '2026-02-19T15:38:19+03:00', '2026-02-19', 'INV7509V1', 'Abir Abou Diab', 'abir abou diab', NULL, 20500, 0, 20500, 0, 0, 20500, 'credit', 'issued', 0, 20500),
(103, 'Branch 1', '2026-02-19T15:40:33.250000+03:00', '2026-02-19', 'INV7511', 'Pepita', 'pepita', NULL, 28000, 0, 28000, 0, 0, 28000, 'credit', 'issued', 0, 28000),
(104, 'Branch 1', '2026-02-19T15:42:10.189000+03:00', '2026-02-19', 'INV7512', 'Ghada Trad', 'ghada trad', NULL, 22500, 0, 22500, 0, 0, 22500, 'credit', 'issued', 0, 22500),
(105, 'Branch 1', '2026-02-19T15:48:25.014000+03:00', '2026-02-19', 'INV7513', 'Mohamad Al Jamal', 'mohamad al jamal', NULL, 4500, 0, 4500, 0, 0, 4500, 'credit', 'issued', 0, 4500),
(106, 'Branch 1', '2026-02-19T15:53:19.819000+03:00', '2026-02-19', 'INV7514', 'Eliana Salloum', 'eliana salloum', NULL, 32500, 0, 32500, 0, 0, 32500, 'credit', 'issued', 0, 32500),
(107, 'Branch 1', '2026-02-19T15:57:16+03:00', '2026-02-19', '100706', 'MARAH', 'marah', NULL, 40000, 0, 40000, 0, 0, 40000, 'credit', 'issued', 0, 40000),
(108, 'Branch 1', '2026-02-19T15:59:12+03:00', '2026-02-19', '100707', 'Marianne Haddad', 'marianne haddad', NULL, 27500, 0, 27500, 0, 0, 27500, 'credit', 'issued', 0, 27500),
(109, 'Branch 1', '2026-02-19T16:01:40+03:00', '2026-02-19', '100708', 'GAT Middle East', 'gat middle east', NULL, 9198000, 0, 9198000, 0, 0, 9198000, 'credit', 'issued', 0, 9198000),
(110, 'Branch 1', '2026-02-20T14:46:04.056000+03:00', '2026-02-20', 'INV7515', 'St Georges And Isaac Church', 'st georges and isaac church', NULL, 3500, 0, 3500, 0, 0, 3500, 'credit', 'issued', 0, 3500),
(111, 'Branch 1', '2026-02-20T14:46:31.649000+03:00', '2026-02-20', 'INV7516', 'Wael Fattouh', 'wael fattouh', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(112, 'Branch 1', '2026-02-20T14:46:43.408000+03:00', '2026-02-20', 'INV7517', 'waad', 'waad', NULL, 28000, 0, 28000, 0, 0, 28000, 'credit', 'issued', 0, 28000),
(113, 'Branch 1', '2026-02-20T14:47:01+03:00', '2026-02-20', 'INV7518V1', 'Ghina Charaf', 'ghina charaf', NULL, 30000, 0, 30000, 0, 0, 30000, 'credit', 'issued', 0, 30000),
(114, 'Branch 1', '2026-02-20T14:47:42.963000+03:00', '2026-02-20', 'INV7519', 'Rita Nawar', 'rita nawar', NULL, 34000, 0, 34000, 0, 0, 34000, 'credit', 'issued', 0, 34000),
(115, 'Branch 1', '2026-02-20T14:49:46.496000+03:00', '2026-02-20', 'INV7520', 'Carla Bacha', 'carla bacha', NULL, 10000, 0, 10000, 0, 0, 10000, 'credit', 'issued', 0, 10000),
(116, 'Branch 1', '2026-02-20T14:50:39.175000+03:00', '2026-02-20', 'INV7521', 'Lody', 'lody', NULL, 35000, 0, 35000, 0, 0, 35000, 'credit', 'issued', 0, 35000),
(117, 'Branch 1', '2026-02-20T14:54:21+03:00', '2026-02-20', '100709', 'Funderdome', 'funderdome', NULL, 13000, 0, 13000, 0, 0, 13000, 'credit', 'issued', 0, 13000),
(118, 'Branch 1', '2026-02-21T17:17:14.976000+03:00', '2026-02-21', 'INV7522', 'St Georges And Isaac Church', 'st georges and isaac church', NULL, 3500, 0, 3500, 0, 0, 3500, 'credit', 'issued', 0, 3500),
(119, 'Branch 1', '2026-02-21T17:17:38.725000+03:00', '2026-02-21', 'INV7523', 'Fouad Abdelbaki', 'fouad abdelbaki', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(120, 'Branch 1', '2026-02-21T17:17:48.198000+03:00', '2026-02-21', 'INV7524', 'Fadi El Jam', 'fadi el jam', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(121, 'Branch 1', '2026-02-21T17:17:59.812000+03:00', '2026-02-21', 'INV7525', 'jackie', 'jackie', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(122, 'Branch 1', '2026-02-21T17:18:07.348000+03:00', '2026-02-21', 'INV7526', 'Jihad Abou Chabke', 'jihad abou chabke', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(123, 'Branch 1', '2026-02-21T17:18:17.158000+03:00', '2026-02-21', 'INV7527', 'Roger Abou Malhab', 'roger abou malhab', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(124, 'Branch 1', '2026-02-21T17:18:24.985000+03:00', '2026-02-21', 'INV7528', 'MARK Chidiac', 'mark chidiac', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(125, 'Branch 1', '2026-02-21T17:18:38.998000+03:00', '2026-02-21', 'INV7529', 'Mitche Maroun', 'mitche maroun', NULL, 8000, 0, 8000, 0, 0, 8000, 'credit', 'issued', 0, 8000),
(126, 'Branch 1', '2026-02-21T17:19:46+03:00', '2026-02-21', 'INV7530V1', 'Ghada El Rassi', 'ghada el rassi', NULL, 14100, 100, 14000, 0, 0, 14000, 'credit', 'issued', 0, 14000),
(127, 'Branch 1', '2026-02-21T17:20:50.860000+03:00', '2026-02-21', 'INV7531', 'DALAL', 'dalal', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(128, 'Branch 1', '2026-02-21T17:21:54.427000+03:00', '2026-02-21', 'INV7532', 'Maria Achkar', 'maria achkar', NULL, 13000, 0, 13000, 0, 0, 13000, 'credit', 'issued', 0, 13000),
(129, 'Branch 1', '2026-02-21T17:22:08.180000+03:00', '2026-02-21', 'INV7533', 'Leila Dreik', 'leila dreik', NULL, 14000, 0, 14000, 14000, 0, 0, 'cash', 'paid', 14000, 0),
(130, 'Branch 1', '2026-02-21T17:22:30.784000+03:00', '2026-02-21', 'INV7534', 'Pamela Azzi', 'pamela azzi', NULL, 30000, 0, 30000, 0, 0, 30000, 'credit', 'issued', 0, 30000),
(131, 'Branch 1', '2026-02-21T17:23:09.562000+03:00', '2026-02-21', 'INV7535', 'Elias Khalil', 'elias khalil', NULL, 20000, 0, 20000, 0, 0, 20000, 'credit', 'issued', 0, 20000),
(132, 'Branch 1', '2026-02-21T17:23:40.607000+03:00', '2026-02-21', 'INV7536', 'Samar', 'samar', NULL, 63000, 0, 63000, 0, 0, 63000, 'credit', 'issued', 0, 63000),
(133, 'Branch 1', '2026-02-22T14:32:04+03:00', '2026-02-22', '100710V1', 'Tharwat Kassar', 'tharwat kassar', NULL, 1188000, 68000, 1120000, 0, 0, 1120000, 'credit', 'issued', 0, 1120000),
(134, 'Branch 1', '2026-02-22T15:38:17.582000+03:00', '2026-02-22', 'INV7537', 'St Georges And Isaac Church', 'st georges and isaac church', NULL, 3500, 0, 3500, 0, 0, 3500, 'credit', 'issued', 0, 3500),
(135, 'Branch 1', '2026-02-22T15:39:12.786000+03:00', '2026-02-22', 'INV7538', 'GHADA MAALOUF', 'ghada maalouf', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(136, 'Branch 1', '2026-02-22T15:39:44.266000+03:00', '2026-02-22', 'INV7539', 'Eliane Daccache', 'eliane daccache', NULL, 8000, 0, 8000, 0, 0, 8000, 'credit', 'issued', 0, 8000),
(137, 'Branch 1', '2026-02-22T15:39:54.105000+03:00', '2026-02-22', 'INV7540', 'Fadi El Jam', 'fadi el jam', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(138, 'Branch 1', '2026-02-22T15:40:03.048000+03:00', '2026-02-22', 'INV7541', 'jackie', 'jackie', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(139, 'Branch 1', '2026-02-22T15:40:13.267000+03:00', '2026-02-22', 'INV7542', 'Antonio', 'antonio', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(140, 'Branch 1', '2026-02-22T15:40:19.091000+03:00', '2026-02-22', 'INV7543', 'Jihad Abou Chabke', 'jihad abou chabke', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(141, 'Branch 1', '2026-02-22T15:40:32.380000+03:00', '2026-02-22', 'INV7544', 'Roger Abou Malhab', 'roger abou malhab', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(142, 'Branch 1', '2026-02-22T15:41:29.853000+03:00', '2026-02-22', 'INV7545', 'Mitche Maroun', 'mitche maroun', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(143, 'Branch 1', '2026-02-22T15:41:37.619000+03:00', '2026-02-22', 'INV7546', 'MARK Chidiac', 'mark chidiac', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(144, 'Branch 1', '2026-02-22T15:41:46.534000+03:00', '2026-02-22', 'INV7547', 'Saeed Zeidan', 'saeed zeidan', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(145, 'Branch 1', '2026-02-22T15:42:08.257000+03:00', '2026-02-22', 'INV7548', 'Nour Khoury', 'nour khoury', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(146, 'Branch 1', '2026-02-22T15:42:16.764000+03:00', '2026-02-22', 'INV7549', 'PIA', 'pia', NULL, 21500, 0, 21500, 0, 0, 21500, 'credit', 'issued', 0, 21500),
(147, 'Branch 1', '2026-02-22T15:42:47.948000+03:00', '2026-02-22', 'INV7550', 'Wael Fattouh', 'wael fattouh', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(148, 'Branch 1', '2026-02-22T15:42:55.328000+03:00', '2026-02-22', 'INV7551', 'chady', 'chady', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(149, 'Branch 1', '2026-02-22T15:43:14.379000+03:00', '2026-02-22', 'INV7552', 'Roula Ismail', 'roula ismail', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(150, 'Branch 1', '2026-02-22T15:43:25.109000+03:00', '2026-02-22', 'INV7553', 'DALAL', 'dalal', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(151, 'Branch 1', '2026-02-22T15:44:04.680000+03:00', '2026-02-22', 'INV7554', 'Mohamad Al Jamal', 'mohamad al jamal', NULL, 4500, 0, 4500, 0, 0, 4500, 'credit', 'issued', 0, 4500),
(152, 'Branch 1', '2026-02-22T15:44:48+03:00', '2026-02-22', 'INV7555V1', 'Rima Nahle', 'rima nahle', NULL, 9000, 0, 9000, 0, 0, 9000, 'credit', 'issued', 0, 9000),
(153, 'Branch 1', '2026-02-22T15:45:03.089000+03:00', '2026-02-22', 'INV7556', 'Youssef R', 'youssef r', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(154, 'Branch 1', '2026-02-22T15:45:10.621000+03:00', '2026-02-22', 'INV7557', 'Melody R', 'melody r', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(155, 'Branch 1', '2026-02-22T15:45:19.412000+03:00', '2026-02-22', 'INV7558', 'Nasri Rbeiz', 'nasri rbeiz', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(156, 'Branch 1', '2026-02-22T15:45:30.840000+03:00', '2026-02-22', 'INV7559', 'MOUNIRA', 'mounira', NULL, 6500, 0, 6500, 0, 0, 6500, 'credit', 'issued', 0, 6500),
(157, 'Branch 1', '2026-02-22T15:45:40.774000+03:00', '2026-02-22', 'INV7560', 'Fouad Abdelbaki', 'fouad abdelbaki', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(158, 'Branch 1', '2026-02-22T15:46:36.573000+03:00', '2026-02-22', 'INV7561', 'Jaber Al Hajiri', 'jaber al hajiri', NULL, 36000, 0, 36000, 0, 0, 36000, 'credit', 'issued', 0, 36000),
(159, 'Branch 1', '2026-02-22T15:48:14.244000+03:00', '2026-02-22', 'INV7562', 'Rawan Hachem', 'rawan hachem', NULL, 53500, 0, 53500, 0, 0, 53500, 'credit', 'issued', 0, 53500),
(160, 'Branch 1', '2026-02-22T15:50:21.308000+03:00', '2026-02-22', 'INV7563', 'Sarah', 'sarah', NULL, 78000, 0, 78000, 0, 0, 78000, 'credit', 'issued', 0, 78000),
(161, 'Branch 1', '2026-02-22T15:56:25.556000+03:00', '2026-02-22', 'INV7564', 'Tharwat Kassar', 'tharwat kassar', NULL, 146000, 16000, 130000, 0, 0, 130000, 'credit', 'issued', 0, 130000),
(162, 'Branch 1', '2026-02-23T13:55:33+03:00', '2026-02-23', '100711', 'UPTC', 'uptc', NULL, 964800, 0, 964800, 0, 0, 964800, 'credit', 'issued', 0, 964800),
(163, 'Branch 1', '2026-02-23T14:15:44.940000+03:00', '2026-02-23', 'INV7565', 'Mada', 'mada', NULL, 13000, 0, 13000, 0, 0, 13000, 'credit', 'issued', 0, 13000),
(164, 'Branch 1', '2026-02-23T14:17:58.125000+03:00', '2026-02-23', 'INV7566', 'Carla', 'carla', NULL, 16000, 0, 16000, 0, 0, 16000, 'credit', 'issued', 0, 16000),
(165, 'Branch 1', '2026-02-23T15:42:32.279000+03:00', '2026-02-23', 'INV7567', 'Sarah', 'sarah', NULL, 70500, 0, 70500, 0, 0, 70500, 'credit', 'issued', 0, 70500),
(166, 'Branch 1', '2026-02-23T15:46:02.481000+03:00', '2026-02-23', 'INV7568', 'Rawan Hachem', 'rawan hachem', NULL, 15000, 0, 15000, 0, 0, 15000, 'credit', 'issued', 0, 15000),
(167, 'Branch 1', '2026-02-23T17:31:02.820000+03:00', '2026-02-23', 'INV7569', 'St Georges And Isaac Church', 'st georges and isaac church', NULL, 3500, 0, 3500, 0, 0, 3500, 'credit', 'issued', 0, 3500),
(168, 'Branch 1', '2026-02-23T17:31:34.503000+03:00', '2026-02-23', 'INV7570', 'GHADA MAALOUF', 'ghada maalouf', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(169, 'Branch 1', '2026-02-23T17:31:43.135000+03:00', '2026-02-23', 'INV7571', 'Fouad Abdelbaki', 'fouad abdelbaki', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(170, 'Branch 1', '2026-02-23T17:31:56.875000+03:00', '2026-02-23', 'INV7572', 'Fadi El Jam', 'fadi el jam', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(171, 'Branch 1', '2026-02-23T17:32:09.316000+03:00', '2026-02-23', 'INV7573', 'jackie', 'jackie', NULL, 16500, 0, 16500, 0, 0, 16500, 'credit', 'issued', 0, 16500),
(172, 'Branch 1', '2026-02-23T17:32:19.323000+03:00', '2026-02-23', 'INV7574', 'Antonio', 'antonio', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(173, 'Branch 1', '2026-02-23T17:32:25.834000+03:00', '2026-02-23', 'INV7575', 'Jihad Abou Chabke', 'jihad abou chabke', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(174, 'Branch 1', '2026-02-23T17:32:33.181000+03:00', '2026-02-23', 'INV7576', 'Roger Abou Malhab', 'roger abou malhab', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(175, 'Branch 1', '2026-02-23T17:32:39.372000+03:00', '2026-02-23', 'INV7577', 'MARK Chidiac', 'mark chidiac', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(176, 'Branch 1', '2026-02-23T17:32:47.010000+03:00', '2026-02-23', 'INV7578', 'Saeed Zeidan', 'saeed zeidan', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(177, 'Branch 1', '2026-02-23T17:32:58.746000+03:00', '2026-02-23', 'INV7579', 'Nour Khoury', 'nour khoury', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(178, 'Branch 1', '2026-02-23T17:33:18.171000+03:00', '2026-02-23', 'INV7580', 'nelly khalil', 'nelly khalil', NULL, 20000, 0, 20000, 0, 0, 20000, 'credit', 'issued', 0, 20000),
(179, 'Branch 1', '2026-02-23T17:33:25.622000+03:00', '2026-02-23', 'INV7581', 'PIA', 'pia', NULL, 21500, 0, 21500, 0, 0, 21500, 'credit', 'issued', 0, 21500),
(180, 'Branch 1', '2026-02-23T17:33:41.177000+03:00', '2026-02-23', 'INV7582', 'Youssef R', 'youssef r', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(181, 'Branch 1', '2026-02-23T17:33:48.921000+03:00', '2026-02-23', 'INV7583', 'Melody R', 'melody r', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(182, 'Branch 1', '2026-02-23T17:33:59.491000+03:00', '2026-02-23', 'INV7584', 'Roula Ismail', 'roula ismail', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(183, 'Branch 1', '2026-02-23T17:34:17.616000+03:00', '2026-02-23', 'INV7585', 'MANAL', 'manal', NULL, 38500, 0, 38500, 0, 0, 38500, 'credit', 'issued', 0, 38500),
(184, 'Branch 1', '2026-02-23T17:35:36.938000+03:00', '2026-02-23', 'INV7586', 'Amanda', 'amanda', NULL, 23000, 0, 23000, 0, 0, 23000, 'credit', 'issued', 0, 23000),
(185, 'Branch 1', '2026-02-23T17:36:09.452000+03:00', '2026-02-23', 'INV7587', 'VICKY', 'vicky', NULL, 10000, 0, 10000, 0, 0, 10000, 'credit', 'issued', 0, 10000),
(186, 'Branch 1', '2026-02-23T17:36:22.885000+03:00', '2026-02-23', 'INV7588', 'Mohamad Al Jamal', 'mohamad al jamal', NULL, 4500, 0, 4500, 0, 0, 4500, 'credit', 'issued', 0, 4500),
(187, 'Branch 1', '2026-02-23T17:36:32.758000+03:00', '2026-02-23', 'INV7589', 'Ghina Othman', 'ghina othman', NULL, 10000, 0, 10000, 0, 0, 10000, 'credit', 'issued', 0, 10000),
(188, 'Branch 1', '2026-02-23T17:36:43.030000+03:00', '2026-02-23', 'INV7590', 'chady', 'chady', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(189, 'Branch 1', '2026-02-23T17:36:55.137000+03:00', '2026-02-23', 'INV7591', 'ABIR', 'abir', NULL, 13000, 0, 13000, 0, 0, 13000, 'credit', 'issued', 0, 13000),
(190, 'Branch 1', '2026-02-23T18:35:12+03:00', '2026-02-23', '100712', 'Keeta', 'keeta', NULL, 29129, 0, 29129, 0, 0, 29129, 'credit', 'issued', 0, 29129),
(191, 'Branch 1', '2026-02-23T18:38:06+03:00', '2026-02-23', '100713', 'Talabat', 'talabat', NULL, 87545, 0, 87545, 0, 0, 87545, 'credit', 'issued', 0, 87545),
(192, 'Branch 1', '2026-02-23T20:01:41+03:00', '2026-02-23', '100714V4', 'Armenian Ambassador', 'armenian ambassador', NULL, 80000, 0, 80000, 0, 0, 80000, 'credit', 'issued', 0, 80000),
(193, 'Branch 1', '2026-02-23T20:18:23+03:00', '2026-02-23', '100715', 'Carla Bacha', 'carla bacha', NULL, 27500, 0, 27500, 0, 0, 27500, 'credit', 'issued', 0, 27500),
(194, 'Branch 1', '2026-02-24T16:40:20+03:00', '2026-02-24', '100716', 'Janine', 'janine', NULL, 54000, 0, 54000, 0, 0, 54000, 'credit', 'issued', 0, 54000),
(195, 'Branch 1', '2026-02-24T16:50:58+03:00', '2026-02-24', '100717', 'Carole Hadi', 'carole hadi', NULL, 22500, 0, 22500, 0, 0, 22500, 'credit', 'issued', 0, 22500),
(196, 'Branch 1', '2026-02-24T16:55:52.493000+03:00', '2026-02-24', 'INV7592', 'St Georges And Isaac Church', 'st georges and isaac church', NULL, 14000, 0, 14000, 0, 0, 14000, 'credit', 'issued', 0, 14000),
(197, 'Branch 1', '2026-02-24T16:56:20.630000+03:00', '2026-02-24', 'INV7593', 'GHADA MAALOUF', 'ghada maalouf', NULL, 16500, 0, 16500, 0, 0, 16500, 'credit', 'issued', 0, 16500),
(198, 'Branch 1', '2026-02-24T16:56:36.514000+03:00', '2026-02-24', 'INV7594', 'Fouad Abdelbaki', 'fouad abdelbaki', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(199, 'Branch 1', '2026-02-24T16:56:49.499000+03:00', '2026-02-24', 'INV7595', 'Eliane Daccache', 'eliane daccache', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(200, 'Branch 1', '2026-02-24T16:57:12.911000+03:00', '2026-02-24', 'INV7596', 'Fadi El Jam', 'fadi el jam', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(201, 'Branch 1', '2026-02-24T16:57:26.351000+03:00', '2026-02-24', 'INV7597', 'jackie', 'jackie', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000);
INSERT INTO tmp_sales_source (source_row_num, warehouse, source_timestamp, business_date, document_no, customer_name, customer_norm, pos_reference, subtotal_cents, discount_cents, total_cents, cash_cents, card_cents, credit_cents, payment_type, status, paid_total_cents, balance_cents) VALUES
(202, 'Branch 1', '2026-02-24T16:57:55.321000+03:00', '2026-02-24', 'INV7598', 'Antonio', 'antonio', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(203, 'Branch 1', '2026-02-24T16:58:01.789000+03:00', '2026-02-24', 'INV7599', 'Jihad Abou Chabke', 'jihad abou chabke', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(204, 'Branch 1', '2026-02-24T16:58:09.262000+03:00', '2026-02-24', 'INV7600', 'Roger Abou Malhab', 'roger abou malhab', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(205, 'Branch 1', '2026-02-24T16:58:46.819000+03:00', '2026-02-24', 'INV7601', 'Saeed Zeidan', 'saeed zeidan', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(206, 'Branch 1', '2026-02-24T16:59:07.268000+03:00', '2026-02-24', 'INV7602', 'Nour Khoury', 'nour khoury', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(207, 'Branch 1', '2026-02-24T16:59:21.754000+03:00', '2026-02-24', 'INV7603', 'Rouba El Khoury', 'rouba el khoury', NULL, 5000, 0, 5000, 0, 0, 5000, 'credit', 'issued', 0, 5000),
(208, 'Branch 1', '2026-02-24T16:59:38.080000+03:00', '2026-02-24', 'INV7604', 'PIA', 'pia', NULL, 21500, 0, 21500, 0, 0, 21500, 'credit', 'issued', 0, 21500),
(209, 'Branch 1', '2026-02-24T17:00:36.641000+03:00', '2026-02-24', 'INV7605', 'Roula Ismail', 'roula ismail', NULL, 10500, 0, 10500, 0, 0, 10500, 'credit', 'issued', 0, 10500),
(210, 'Branch 1', '2026-02-24T17:00:47.948000+03:00', '2026-02-24', 'INV7606', 'Youssef R', 'youssef r', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(211, 'Branch 1', '2026-02-24T17:00:57.976000+03:00', '2026-02-24', 'INV7607', 'Melody R', 'melody r', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(212, 'Branch 1', '2026-02-24T17:01:07.407000+03:00', '2026-02-24', 'INV7608', 'VICKY', 'vicky', NULL, 5000, 0, 5000, 0, 0, 5000, 'credit', 'issued', 0, 5000),
(213, 'Branch 1', '2026-02-24T17:01:18.578000+03:00', '2026-02-24', 'INV7609', 'Mohamad Al Jamal', 'mohamad al jamal', NULL, 4500, 0, 4500, 0, 0, 4500, 'credit', 'issued', 0, 4500),
(214, 'Branch 1', '2026-02-24T17:01:40.836000+03:00', '2026-02-24', 'INV7610', 'Wael Fattouh', 'wael fattouh', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(215, 'Branch 1', '2026-02-24T17:01:49.804000+03:00', '2026-02-24', 'INV7611', 'waad', 'waad', NULL, 18000, 0, 18000, 0, 0, 18000, 'credit', 'issued', 0, 18000),
(216, 'Branch 1', '2026-02-24T17:02:17.246000+03:00', '2026-02-24', 'INV7612', 'chady', 'chady', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(217, 'Branch 1', '2026-02-24T17:02:28.844000+03:00', '2026-02-24', 'INV7613', 'Amanda', 'amanda', NULL, 5000, 0, 5000, 0, 0, 5000, 'credit', 'issued', 0, 5000),
(218, 'Branch 1', '2026-02-24T17:03:03.783000+03:00', '2026-02-24', 'INV7614', 'Seta', 'seta', NULL, 20500, 0, 20500, 0, 0, 20500, 'credit', 'issued', 0, 20500),
(219, 'Branch 1', '2026-02-24T17:04:49.442000+03:00', '2026-02-24', 'INV7615', 'Dana Abaza', 'dana abaza', NULL, 13000, 0, 13000, 0, 0, 13000, 'credit', 'issued', 0, 13000),
(220, 'Branch 1', '2026-02-24T17:05:02.435000+03:00', '2026-02-24', 'INV7616', 'Nasri Rbeiz', 'nasri rbeiz', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(221, 'Branch 1', '2026-02-24T17:05:14.309000+03:00', '2026-02-24', 'INV7617', 'Riham', 'riham', NULL, 14000, 0, 14000, 14000, 0, 0, 'cash', 'paid', 14000, 0),
(222, 'Branch 1', '2026-02-24T17:06:10.799000+03:00', '2026-02-24', 'INV7618', 'Mohamad El Arab', 'mohamad el arab', NULL, 4500, 0, 4500, 0, 0, 4500, 'credit', 'issued', 0, 4500),
(223, 'Branch 1', '2026-02-24T18:21:22+03:00', '2026-02-24', '100718', 'Aya', 'aya', NULL, 183000, 0, 183000, 0, 0, 183000, 'credit', 'issued', 0, 183000),
(224, 'Branch 1', '2026-02-25T14:53:42+03:00', '2026-02-25', '100727', 'St Georges And Isaac Church', 'st georges and isaac church', NULL, 14000, 0, 14000, 0, 0, 14000, 'credit', 'issued', 0, 14000),
(225, 'Branch 1', '2026-02-25T14:54:38+03:00', '2026-02-25', '100728', 'GHADA MAALOUF', 'ghada maalouf', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(226, 'Branch 1', '2026-02-25T14:55:20+03:00', '2026-02-25', '100729', 'Fouad Abdelbaki', 'fouad abdelbaki', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(227, 'Branch 1', '2026-02-25T14:56:03+03:00', '2026-02-25', '100730', 'Eliane Daccache', 'eliane daccache', NULL, 8000, 0, 8000, 0, 0, 8000, 'credit', 'issued', 0, 8000),
(228, 'Branch 1', '2026-02-25T14:56:44+03:00', '2026-02-25', '100731', 'Fadi El Jam', 'fadi el jam', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(229, 'Branch 1', '2026-02-25T14:57:47+03:00', '2026-02-25', '100732', 'jackie', 'jackie', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(230, 'Branch 1', '2026-02-25T14:58:32+03:00', '2026-02-25', '100733', 'Antonio', 'antonio', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(231, 'Branch 1', '2026-02-25T14:59:01+03:00', '2026-02-25', '100734', 'Jihad Abou Chabke', 'jihad abou chabke', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(232, 'Branch 1', '2026-02-25T14:59:28+03:00', '2026-02-25', '100735', 'Roger Abou Malhab', 'roger abou malhab', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(233, 'Branch 1', '2026-02-25T15:00:06+03:00', '2026-02-25', '100736', 'Saeed Zeidan', 'saeed zeidan', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(234, 'Branch 1', '2026-02-25T15:00:48+03:00', '2026-02-25', '100737', 'Nour Khoury', 'nour khoury', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(235, 'Branch 1', '2026-02-25T15:35:51+03:00', '2026-02-25', '100719V1', 'Advance Heavy Rocks Trading W.L.L.', 'advance heavy rocks trading w.l.l.', NULL, 1394000, 0, 1394000, 0, 0, 1394000, 'credit', 'issued', 0, 1394000),
(236, 'Branch 1', '2026-02-25T16:13:33+03:00', '2026-02-25', '100720', 'Nicole Nassif', 'nicole nassif', NULL, 12000, 0, 12000, 0, 0, 12000, 'credit', 'issued', 0, 12000),
(237, 'Branch 1', '2026-02-25T16:22:21+03:00', '2026-02-25', '100738', 'Rouba El Khoury', 'rouba el khoury', NULL, 5000, 0, 5000, 0, 0, 5000, 'credit', 'issued', 0, 5000),
(238, 'Branch 1', '2026-02-25T16:22:59+03:00', '2026-02-25', '100739', 'nelly khalil', 'nelly khalil', NULL, 20000, 0, 20000, 0, 0, 20000, 'credit', 'issued', 0, 20000),
(239, 'Branch 1', '2026-02-25T16:25:51+03:00', '2026-02-25', '100740V1', 'PIA', 'pia', NULL, 21500, 0, 21500, 0, 0, 21500, 'credit', 'issued', 0, 21500),
(240, 'Branch 1', '2026-02-25T16:27:13+03:00', '2026-02-25', '100721', 'Tareq Beirekdar', 'tareq beirekdar', NULL, 42900, 0, 42900, 0, 0, 42900, 'credit', 'issued', 0, 42900),
(241, 'Branch 1', '2026-02-25T16:28:59+03:00', '2026-02-25', '100741', 'Roula Ismail', 'roula ismail', NULL, 10500, 0, 10500, 0, 0, 10500, 'credit', 'issued', 0, 10500),
(242, 'Branch 1', '2026-02-25T16:30:14+03:00', '2026-02-25', '100742', 'Youssef R', 'youssef r', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(243, 'Branch 1', '2026-02-25T16:31:25+03:00', '2026-02-25', '100743', 'Melody R', 'melody r', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(244, 'Branch 1', '2026-02-25T16:33:07+03:00', '2026-02-25', '100744', 'VICKY', 'vicky', NULL, 5000, 0, 5000, 0, 0, 5000, 'credit', 'issued', 0, 5000),
(245, 'Branch 1', '2026-02-25T16:34:00+03:00', '2026-02-25', '100745', 'Mohamad Al Jamal', 'mohamad al jamal', NULL, 4500, 0, 4500, 0, 0, 4500, 'credit', 'issued', 0, 4500),
(246, 'Branch 1', '2026-02-25T16:34:59+03:00', '2026-02-25', '100746', 'chady', 'chady', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(247, 'Branch 1', '2026-02-25T16:35:25+03:00', '2026-02-25', '100722', 'Salwa', 'salwa', NULL, 39000, 0, 39000, 0, 0, 39000, 'credit', 'issued', 0, 39000),
(248, 'Branch 1', '2026-02-25T16:35:40+03:00', '2026-02-25', '100747', 'DALAL', 'dalal', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(249, 'Branch 1', '2026-02-25T16:36:16+03:00', '2026-02-25', '100748V1', 'sahar tabet', 'sahar tabet', NULL, 7000, 0, 7000, 0, 0, 7000, 'credit', 'issued', 0, 7000),
(250, 'Branch 1', '2026-02-25T16:36:50+03:00', '2026-02-25', '100723', 'Nivine', 'nivine', NULL, 103500, 0, 103500, 0, 0, 103500, 'credit', 'issued', 0, 103500),
(251, 'Branch 1', '2026-02-25T16:37:13+03:00', '2026-02-25', '100749', 'MANAL', 'manal', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(252, 'Branch 1', '2026-02-25T16:40:42+03:00', '2026-02-25', '100750', 'Zeina Maddah', 'zeina maddah', NULL, 14000, 0, 14000, 0, 0, 14000, 'credit', 'issued', 0, 14000),
(253, 'Branch 1', '2026-02-25T16:42:56+03:00', '2026-02-25', '100724', 'Zeina Khoury', 'zeina khoury', NULL, 36400, 0, 36400, 0, 0, 36400, 'credit', 'issued', 0, 36400),
(254, 'Branch 1', '2026-02-25T16:45:57+03:00', '2026-02-25', '100725', 'Dima Merhebi', 'dima merhebi', NULL, 8000, 0, 8000, 0, 0, 8000, 'credit', 'issued', 0, 8000),
(255, 'Branch 1', '2026-02-25T17:00:16+03:00', '2026-02-25', '100726', 'Samo', 'samo', NULL, 36000, 0, 36000, 0, 0, 36000, 'credit', 'issued', 0, 36000),
(256, 'Branch 1', '2026-02-26T11:03:29.517000+03:00', '2026-02-26', 'INV7619', 'Caroline Ghossain', 'caroline ghossain', NULL, 51500, 0, 51500, 0, 0, 51500, 'credit', 'issued', 0, 51500),
(257, 'Branch 1', '2026-02-26T11:07:38.350000+03:00', '2026-02-26', 'INV7620', 'Suzanne Rahme', 'suzanne rahme', NULL, 34500, 0, 34500, 0, 0, 34500, 'credit', 'issued', 0, 34500),
(258, 'Branch 1', '2026-02-26T16:58:21.248000+03:00', '2026-02-26', 'INV7621', 'St Georges And Isaac Church', 'st georges and isaac church', NULL, 14000, 0, 14000, 0, 0, 14000, 'credit', 'issued', 0, 14000),
(259, 'Branch 1', '2026-02-26T16:59:46.361000+03:00', '2026-02-26', 'INV7622', 'Fouad Abdelbaki', 'fouad abdelbaki', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(260, 'Branch 1', '2026-02-26T16:59:53.382000+03:00', '2026-02-26', 'INV7623', 'Fadi El Jam', 'fadi el jam', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(261, 'Branch 1', '2026-02-26T17:00:05.882000+03:00', '2026-02-26', 'INV7624', 'jackie', 'jackie', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(262, 'Branch 1', '2026-02-26T17:00:19.605000+03:00', '2026-02-26', 'INV7625', 'Antonio', 'antonio', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(263, 'Branch 1', '2026-02-26T17:00:28.292000+03:00', '2026-02-26', 'INV7626', 'Jihad Abou Chabke', 'jihad abou chabke', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(264, 'Branch 1', '2026-02-26T17:00:37.032000+03:00', '2026-02-26', 'INV7627', 'Roger Abou Malhab', 'roger abou malhab', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(265, 'Branch 1', '2026-02-26T17:00:47.130000+03:00', '2026-02-26', 'INV7628', 'Saeed Zeidan', 'saeed zeidan', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(266, 'Branch 1', '2026-02-26T17:01:00.220000+03:00', '2026-02-26', 'INV7629', 'Nour Khoury', 'nour khoury', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(267, 'Branch 1', '2026-02-26T17:01:14.353000+03:00', '2026-02-26', 'INV7630', 'PIA', 'pia', NULL, 21500, 0, 21500, 0, 0, 21500, 'credit', 'issued', 0, 21500),
(268, 'Branch 1', '2026-02-26T17:01:35.129000+03:00', '2026-02-26', 'INV7631', 'Roula Ismail', 'roula ismail', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(269, 'Branch 1', '2026-02-26T17:01:44.137000+03:00', '2026-02-26', 'INV7632', 'VICKY', 'vicky', NULL, 5000, 0, 5000, 0, 0, 5000, 'credit', 'issued', 0, 5000),
(270, 'Branch 1', '2026-02-26T17:01:54.882000+03:00', '2026-02-26', 'INV7633', 'Nasri Rbeiz', 'nasri rbeiz', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(271, 'Branch 1', '2026-02-26T17:02:11.304000+03:00', '2026-02-26', 'INV7634', 'Sandy semaan', 'sandy semaan', NULL, 13000, 0, 13000, 0, 0, 13000, 'credit', 'issued', 0, 13000),
(272, 'Branch 1', '2026-02-26T17:02:23.916000+03:00', '2026-02-26', 'INV7635', 'chady', 'chady', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(273, 'Branch 1', '2026-02-26T17:03:23.179000+03:00', '2026-02-26', 'INV7636', 'Eliane Daccache', 'eliane daccache', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(274, 'Branch 1', '2026-02-26T17:03:32.593000+03:00', '2026-02-26', 'INV7637', 'Wael Fattouh', 'wael fattouh', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(275, 'Branch 1', '2026-02-26T17:03:45.774000+03:00', '2026-02-26', 'INV7638', 'ABIR', 'abir', NULL, 17000, 0, 17000, 0, 0, 17000, 'credit', 'issued', 0, 17000),
(276, 'Branch 1', '2026-02-26T17:05:40.617000+03:00', '2026-02-26', 'INV7639', 'Alex', 'alex', NULL, 10000, 0, 10000, 0, 0, 10000, 'credit', 'issued', 0, 10000),
(277, 'Branch 1', '2026-02-26T17:07:22.935000+03:00', '2026-02-26', 'INV7640', 'Sarah', 'sarah', NULL, 54000, 0, 54000, 0, 0, 54000, 'credit', 'issued', 0, 54000),
(278, 'Branch 1', '2026-02-26T17:11:03.204000+03:00', '2026-02-26', 'INV7641', 'Firas El Beaino', 'firas el beaino', NULL, 28500, 0, 28500, 0, 0, 28500, 'credit', 'issued', 0, 28500),
(279, 'Branch 1', '2026-02-26T17:11:47.817000+03:00', '2026-02-26', 'INV7642', 'Marianne Haddad', 'marianne haddad', NULL, 60500, 0, 60500, 0, 0, 60500, 'credit', 'issued', 0, 60500),
(280, 'Branch 1', '2026-02-26T17:14:29.508000+03:00', '2026-02-26', 'INV7643', 'Roula Talih', 'roula talih', NULL, 53000, 0, 53000, 0, 0, 53000, 'credit', 'issued', 0, 53000),
(281, 'Branch 1', '2026-02-27T12:34:00+03:00', '2026-02-27', 'INV7644V1', 'Luxury Squared Trading w.l.l.', 'luxury squared trading w.l.l.', NULL, 139500, 0, 139500, 0, 0, 139500, 'credit', 'issued', 0, 139500),
(282, 'Branch 1', '2026-02-27T12:37:24.720000+03:00', '2026-02-27', 'INV7645', 'Jaymay', 'jaymay', NULL, 109500, 0, 109500, 0, 0, 109500, 'credit', 'issued', 0, 109500),
(283, 'Branch 1', '2026-02-27T12:40:15.525000+03:00', '2026-02-27', 'INV7646', 'Mireille Khoury', 'mireille khoury', NULL, 12000, 0, 12000, 0, 0, 12000, 'credit', 'issued', 0, 12000),
(284, 'Branch 1', '2026-02-27T13:56:23.452000+03:00', '2026-02-27', 'INV7647', 'Helen Nasr', 'helen nasr', NULL, 18000, 0, 18000, 0, 0, 18000, 'credit', 'issued', 0, 18000),
(285, 'Branch 1', '2026-02-27T13:58:04.115000+03:00', '2026-02-27', 'INV7648', 'VICKY', 'vicky', NULL, 26500, 0, 26500, 0, 0, 26500, 'credit', 'issued', 0, 26500),
(286, 'Branch 1', '2026-02-27T13:59:25.702000+03:00', '2026-02-27', 'INV7649', 'Ghada Trad', 'ghada trad', NULL, 31400, 0, 31400, 0, 0, 31400, 'credit', 'issued', 0, 31400),
(287, 'Branch 1', '2026-02-27T14:02:19.356000+03:00', '2026-02-27', 'INV7650', 'Sunday School', 'sunday school', NULL, 286000, 0, 286000, 0, 0, 286000, 'credit', 'issued', 0, 286000),
(288, 'Branch 1', '2026-02-27T14:03:16.551000+03:00', '2026-02-27', 'INV7651', 'Hoda', 'hoda', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(289, 'Branch 1', '2026-02-27T14:04:56.563000+03:00', '2026-02-27', 'INV7652', 'Nicole Chebl', 'nicole chebl', NULL, 23500, 0, 23500, 0, 0, 23500, 'credit', 'issued', 0, 23500),
(290, 'Branch 1', '2026-02-27T14:06:34+03:00', '2026-02-27', 'INV7653V2', 'Tony Sadaka', 'tony sadaka', NULL, 18000, 0, 18000, 0, 0, 18000, 'credit', 'issued', 0, 18000),
(291, 'Branch 1', '2026-02-27T14:08:51.127000+03:00', '2026-02-27', 'INV7654', 'Aya', 'aya', NULL, 115000, 0, 115000, 0, 0, 115000, 'credit', 'issued', 0, 115000),
(292, 'Branch 1', '2026-02-27T14:09:40.642000+03:00', '2026-02-27', 'INV7655', 'Carla Chemaly', 'carla chemaly', NULL, 162500, 0, 162500, 0, 0, 162500, 'credit', 'issued', 0, 162500),
(293, 'Branch 1', '2026-02-27T21:07:59.659000+03:00', '2026-02-27', 'INV7656', 'Sandy Bachaalany', 'sandy bachaalany', NULL, 150900, 0, 150900, 0, 0, 150900, 'credit', 'issued', 0, 150900),
(294, 'Branch 1', '2026-02-27T21:12:53.391000+03:00', '2026-02-27', 'INV7657', 'NADA', 'nada', NULL, 27000, 0, 27000, 0, 0, 27000, 'credit', 'issued', 0, 27000),
(295, 'Branch 1', '2026-02-27T21:13:36.982000+03:00', '2026-02-27', 'INV7658', 'Janet Chammas', 'janet chammas', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(296, 'Branch 1', '2026-02-28T13:08:13+03:00', '2026-02-28', '100751', 'Emcor Facilities Services w.l.l.', 'emcor facilities services w.l.l.', NULL, 1069500, 0, 1069500, 0, 0, 1069500, 'credit', 'issued', 0, 1069500),
(297, 'Branch 1', '2026-02-28T14:52:18.321000+03:00', '2026-02-28', 'INV7659', 'Wael Fattouh', 'wael fattouh', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(298, 'Branch 1', '2026-02-28T14:52:45.651000+03:00', '2026-02-28', 'INV7660', 'Mohamad Al Jamal', 'mohamad al jamal', NULL, 4500, 0, 4500, 0, 0, 4500, 'credit', 'issued', 0, 4500),
(299, 'Branch 1', '2026-02-28T14:52:59.003000+03:00', '2026-02-28', 'INV7661', 'Rima Nahle', 'rima nahle', NULL, 9000, 0, 9000, 0, 0, 9000, 'credit', 'issued', 0, 9000),
(300, 'Branch 1', '2026-02-28T14:54:17.083000+03:00', '2026-02-28', 'INV7662', 'Lynn Fattouh', 'lynn fattouh', NULL, 48000, 0, 48000, 0, 0, 48000, 'credit', 'issued', 0, 48000),
(301, 'Branch 1', '2026-02-28T14:55:43.645000+03:00', '2026-02-28', 'INV7663', 'Salwa', 'salwa', NULL, 27500, 0, 27500, 0, 0, 27500, 'credit', 'issued', 0, 27500),
(302, 'Branch 1', '2026-02-28T15:51:17.502000+03:00', '2026-02-28', 'INV7664', 'St Georges And Isaac Church', 'st georges and isaac church', NULL, 14000, 0, 14000, 0, 0, 14000, 'credit', 'issued', 0, 14000),
(303, 'Branch 1', '2026-02-28T15:51:30.121000+03:00', '2026-02-28', 'INV7665', 'GHADA MAALOUF', 'ghada maalouf', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(304, 'Branch 1', '2026-02-28T15:51:36.701000+03:00', '2026-02-28', 'INV7666', 'Fadi El Jam', 'fadi el jam', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(305, 'Branch 1', '2026-02-28T15:51:42.750000+03:00', '2026-02-28', 'INV7667', 'Jihad Abou Chabke', 'jihad abou chabke', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(306, 'Branch 1', '2026-02-28T15:51:55.097000+03:00', '2026-02-28', 'INV7668', 'Roula Ismail', 'roula ismail', NULL, 5500, 0, 5500, 0, 0, 5500, 'credit', 'issued', 0, 5500),
(307, 'Branch 1', '2026-02-28T15:52:18.472000+03:00', '2026-02-28', 'INV7669', 'VICKY', 'vicky', NULL, 5000, 0, 5000, 0, 0, 5000, 'credit', 'issued', 0, 5000),
(308, 'Branch 1', '2026-02-28T15:52:33.425000+03:00', '2026-02-28', 'INV7670', 'MANAL', 'manal', NULL, 16500, 0, 16500, 0, 0, 16500, 'credit', 'issued', 0, 16500),
(309, 'Branch 1', '2026-02-28T15:52:50.949000+03:00', '2026-02-28', 'INV7671', 'Eliane Daccache', 'eliane daccache', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(310, 'Branch 1', '2026-02-28T15:53:13.134000+03:00', '2026-02-28', 'INV7672', 'Rouba El Khoury', 'rouba el khoury', NULL, 7000, 0, 7000, 0, 0, 7000, 'credit', 'issued', 0, 7000),
(311, 'Branch 1', '2026-02-28T15:56:43.885000+03:00', '2026-02-28', 'INV7673', 'Hiba Zaatari', 'hiba zaatari', NULL, 8000, 0, 8000, 0, 0, 8000, 'credit', 'issued', 0, 8000),
(312, 'Branch 1', '2026-02-28T16:03:13.968000+03:00', '2026-02-28', 'INV7674', 'Samar', 'samar', NULL, 39500, 0, 39500, 0, 0, 39500, 'credit', 'issued', 0, 39500);

DROP TEMPORARY TABLE IF EXISTS tmp_customer_source;
CREATE TEMPORARY TABLE tmp_customer_source AS
SELECT
  customer_norm,
  MIN(customer_name) AS customer_name
FROM tmp_sales_source
GROUP BY customer_norm;
ALTER TABLE tmp_customer_source
  ADD PRIMARY KEY (customer_norm);

DROP TEMPORARY TABLE IF EXISTS tmp_customer_name_counts;
CREATE TEMPORARY TABLE tmp_customer_name_counts AS
SELECT
  LOWER(TRIM(c.name)) COLLATE utf8mb4_unicode_ci AS customer_norm,
  COUNT(*) AS target_count
FROM customers c
GROUP BY LOWER(TRIM(c.name)) COLLATE utf8mb4_unicode_ci;
ALTER TABLE tmp_customer_name_counts
  ADD PRIMARY KEY (customer_norm);

DROP TEMPORARY TABLE IF EXISTS tmp_customer_unique_names;
CREATE TEMPORARY TABLE tmp_customer_unique_names AS
SELECT
  cc.customer_norm,
  c.id AS customer_id
FROM tmp_customer_name_counts cc
JOIN customers c
  ON LOWER(TRIM(c.name)) COLLATE utf8mb4_unicode_ci = cc.customer_norm
WHERE cc.target_count = 1;
ALTER TABLE tmp_customer_unique_names
  ADD PRIMARY KEY (customer_norm),
  ADD KEY idx_tmp_customer_unique_names_customer_id (customer_id);

DROP TEMPORARY TABLE IF EXISTS tmp_customer_ambiguous_names;
CREATE TEMPORARY TABLE tmp_customer_ambiguous_names AS
SELECT customer_norm, target_count
FROM tmp_customer_name_counts
WHERE target_count > 1;
ALTER TABLE tmp_customer_ambiguous_names
  ADD PRIMARY KEY (customer_norm);

DROP TEMPORARY TABLE IF EXISTS tmp_missing_customers;
CREATE TEMPORARY TABLE tmp_missing_customers AS
SELECT
  s.customer_norm,
  s.customer_name
FROM tmp_customer_source s
LEFT JOIN tmp_customer_name_counts c ON c.customer_norm = s.customer_norm
WHERE c.customer_norm IS NULL;
ALTER TABLE tmp_missing_customers
  ADD PRIMARY KEY (customer_norm);

SET @next_customer_num := (
  SELECT COALESCE(MAX(CAST(SUBSTRING(customer_code, 6) AS UNSIGNED)), 0) + 1
  FROM customers
  WHERE customer_code REGEXP '^CUST-[0-9]+$'
);
SET @customer_row_num := 0;
INSERT INTO customers (
  customer_code,
  name,
  customer_type,
  credit_limit,
  is_active,
  created_at,
  updated_at
)
SELECT
  CONCAT('CUST-', LPAD(CAST(@next_customer_num + (@customer_row_num := @customer_row_num + 1) - 1 AS CHAR), 4, '0')) AS customer_code,
  m.customer_name AS name,
  'retail' AS customer_type,
  0.000 AS credit_limit,
  1 AS is_active,
  NOW() AS created_at,
  NOW() AS updated_at
FROM tmp_missing_customers m
ORDER BY m.customer_norm;
SET @inserted_customers := ROW_COUNT();

DROP TEMPORARY TABLE IF EXISTS tmp_customer_name_counts_final;
CREATE TEMPORARY TABLE tmp_customer_name_counts_final AS
SELECT
  LOWER(TRIM(c.name)) COLLATE utf8mb4_unicode_ci AS customer_norm,
  COUNT(*) AS target_count
FROM customers c
GROUP BY LOWER(TRIM(c.name)) COLLATE utf8mb4_unicode_ci;
ALTER TABLE tmp_customer_name_counts_final
  ADD PRIMARY KEY (customer_norm);

DROP TEMPORARY TABLE IF EXISTS tmp_customer_unique_names_final;
CREATE TEMPORARY TABLE tmp_customer_unique_names_final AS
SELECT
  cc.customer_norm,
  c.id AS customer_id
FROM tmp_customer_name_counts_final cc
JOIN customers c
  ON LOWER(TRIM(c.name)) COLLATE utf8mb4_unicode_ci = cc.customer_norm
WHERE cc.target_count = 1;
ALTER TABLE tmp_customer_unique_names_final
  ADD PRIMARY KEY (customer_norm),
  ADD KEY idx_tmp_customer_unique_names_final_customer_id (customer_id);

DROP TEMPORARY TABLE IF EXISTS tmp_customer_ambiguous_names_final;
CREATE TEMPORARY TABLE tmp_customer_ambiguous_names_final AS
SELECT customer_norm, target_count
FROM tmp_customer_name_counts_final
WHERE target_count > 1;
ALTER TABLE tmp_customer_ambiguous_names_final
  ADD PRIMARY KEY (customer_norm);

DROP TEMPORARY TABLE IF EXISTS tmp_sales_customer_resolution;
CREATE TEMPORARY TABLE tmp_sales_customer_resolution AS
SELECT
  s.source_row_num,
  s.customer_norm,
  cu.customer_id,
  CASE
    WHEN ca.customer_norm IS NOT NULL THEN 'ambiguous'
    WHEN cu.customer_id IS NULL THEN 'missing'
    ELSE 'resolved'
  END AS customer_resolution
FROM tmp_sales_source s
LEFT JOIN tmp_customer_unique_names_final cu ON cu.customer_norm = s.customer_norm
LEFT JOIN tmp_customer_ambiguous_names_final ca ON ca.customer_norm = s.customer_norm;
ALTER TABLE tmp_sales_customer_resolution
  ADD PRIMARY KEY (source_row_num),
  ADD KEY idx_tmp_sales_customer_resolution_state (customer_resolution),
  ADD KEY idx_tmp_sales_customer_resolution_customer_id (customer_id);

DROP TEMPORARY TABLE IF EXISTS tmp_invoice_resolution;
CREATE TEMPORARY TABLE tmp_invoice_resolution AS
SELECT
  s.source_row_num,
  s.document_no,
  s.pos_reference,
  cr.customer_id,
  cr.customer_resolution,
  inv_num.id AS invoice_by_number_id,
  inv_pos.id AS invoice_by_pos_id,
  CASE
    WHEN cr.customer_resolution <> 'resolved' THEN 'skip_customer'
    WHEN inv_num.id IS NOT NULL AND inv_pos.id IS NOT NULL AND inv_num.id <> inv_pos.id THEN 'skip_conflict'
    WHEN inv_num.id IS NULL AND inv_pos.id IS NULL THEN 'insert'
    ELSE 'update'
  END AS resolution_status,
  COALESCE(inv_num.id, inv_pos.id) AS resolved_invoice_id
FROM tmp_sales_source s
JOIN tmp_sales_customer_resolution cr ON cr.source_row_num = s.source_row_num
LEFT JOIN ar_invoices inv_num
  ON inv_num.branch_id = 1
  AND inv_num.type = 'invoice'
  AND inv_num.invoice_number COLLATE utf8mb4_unicode_ci = s.document_no COLLATE utf8mb4_unicode_ci
LEFT JOIN ar_invoices inv_pos
  ON inv_pos.branch_id = 1
  AND inv_pos.type = 'invoice'
  AND s.pos_reference IS NOT NULL
  AND inv_pos.pos_reference COLLATE utf8mb4_unicode_ci = s.pos_reference COLLATE utf8mb4_unicode_ci;
ALTER TABLE tmp_invoice_resolution
  ADD PRIMARY KEY (source_row_num),
  ADD KEY idx_tmp_invoice_resolution_status (resolution_status),
  ADD KEY idx_tmp_invoice_resolution_invoice_id (resolved_invoice_id);

UPDATE ar_invoices ai
JOIN tmp_invoice_resolution r
  ON r.resolution_status = 'update'
 AND r.resolved_invoice_id = ai.id
JOIN tmp_sales_source s ON s.source_row_num = r.source_row_num
SET
  ai.customer_id = r.customer_id,
  ai.source = 'import',
  ai.type = 'invoice',
  ai.invoice_number = s.document_no,
  ai.status = s.status,
  ai.payment_type = s.payment_type,
  ai.issue_date = s.business_date,
  ai.due_date = s.business_date,
  ai.currency = 'QAR',
  ai.subtotal_cents = s.subtotal_cents,
  ai.discount_total_cents = s.discount_cents,
  ai.invoice_discount_type = 'fixed',
  ai.invoice_discount_value = s.discount_cents,
  ai.invoice_discount_cents = s.discount_cents,
  ai.tax_total_cents = 0,
  ai.total_cents = s.total_cents,
  ai.paid_total_cents = s.paid_total_cents,
  ai.balance_cents = s.balance_cents,
  ai.pos_reference = s.pos_reference,
  ai.created_at = COALESCE(STR_TO_DATE(LEFT(REPLACE(s.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s'), ai.created_at),
  ai.updated_at = COALESCE(STR_TO_DATE(LEFT(REPLACE(s.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s'), ai.updated_at);
SET @updated_invoice_rows := ROW_COUNT();

INSERT INTO ar_invoices (
  branch_id,
  customer_id,
  source,
  type,
  invoice_number,
  status,
  payment_type,
  issue_date,
  due_date,
  currency,
  subtotal_cents,
  discount_total_cents,
  invoice_discount_type,
  invoice_discount_value,
  invoice_discount_cents,
  tax_total_cents,
  total_cents,
  paid_total_cents,
  balance_cents,
  pos_reference,
  notes,
  created_at,
  updated_at
)
SELECT
  1 AS branch_id,
  r.customer_id,
  'import' AS source,
  'invoice' AS type,
  s.document_no AS invoice_number,
  s.status,
  s.payment_type,
  s.business_date AS issue_date,
  s.business_date AS due_date,
  'QAR' AS currency,
  s.subtotal_cents,
  s.discount_cents AS discount_total_cents,
  'fixed' AS invoice_discount_type,
  s.discount_cents AS invoice_discount_value,
  s.discount_cents AS invoice_discount_cents,
  0 AS tax_total_cents,
  s.total_cents,
  s.paid_total_cents,
  s.balance_cents,
  s.pos_reference,
  'Imported from Sales Entry Daily Report' AS notes,
  COALESCE(STR_TO_DATE(LEFT(REPLACE(s.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s'), NOW()) AS created_at,
  COALESCE(STR_TO_DATE(LEFT(REPLACE(s.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s'), NOW()) AS updated_at
FROM tmp_invoice_resolution r
JOIN tmp_sales_source s ON s.source_row_num = r.source_row_num
WHERE r.resolution_status = 'insert'
ORDER BY s.source_row_num;
SET @inserted_invoice_rows := ROW_COUNT();

DROP TEMPORARY TABLE IF EXISTS tmp_target_invoice_ids;
CREATE TEMPORARY TABLE tmp_target_invoice_ids AS
SELECT
  r.source_row_num,
  CASE
    WHEN r.resolution_status = 'update' THEN r.resolved_invoice_id
    ELSE ai.id
  END AS invoice_id
FROM tmp_invoice_resolution r
JOIN tmp_sales_source s ON s.source_row_num = r.source_row_num
LEFT JOIN ar_invoices ai
  ON r.resolution_status = 'insert'
 AND ai.branch_id = 1
 AND ai.type = 'invoice'
 AND ai.invoice_number COLLATE utf8mb4_unicode_ci = s.document_no COLLATE utf8mb4_unicode_ci
WHERE r.resolution_status IN ('insert', 'update');
ALTER TABLE tmp_target_invoice_ids
  ADD PRIMARY KEY (source_row_num),
  ADD KEY idx_tmp_target_invoice_ids_invoice_id (invoice_id);

DELETE ii
FROM ar_invoice_items ii
JOIN (SELECT DISTINCT invoice_id FROM tmp_target_invoice_ids) t
  ON t.invoice_id = ii.invoice_id;
SET @deleted_invoice_item_rows := ROW_COUNT();

INSERT INTO ar_invoice_items (
  invoice_id,
  description,
  qty,
  unit_price_cents,
  discount_cents,
  tax_cents,
  line_total_cents,
  created_at,
  updated_at
)
SELECT
  t.invoice_id,
  'Legacy import' AS description,
  1.000 AS qty,
  s.total_cents AS unit_price_cents,
  0 AS discount_cents,
  0 AS tax_cents,
  s.total_cents AS line_total_cents,
  COALESCE(STR_TO_DATE(LEFT(REPLACE(s.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s'), NOW()) AS created_at,
  COALESCE(STR_TO_DATE(LEFT(REPLACE(s.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s'), NOW()) AS updated_at
FROM tmp_target_invoice_ids t
JOIN tmp_sales_source s ON s.source_row_num = t.source_row_num
ORDER BY t.source_row_num;
SET @inserted_invoice_item_rows := ROW_COUNT();

SET @source_rows_loaded := (SELECT COUNT(*) FROM tmp_sales_source);
SET @source_distinct_customers := (SELECT COUNT(*) FROM tmp_customer_source);
SET @skipped_conflict_rows := (
  SELECT COUNT(*) FROM tmp_invoice_resolution WHERE resolution_status = 'skip_conflict'
);
SET @skipped_customer_rows := (
  SELECT COUNT(*) FROM tmp_invoice_resolution WHERE resolution_status = 'skip_customer'
);

-- Summary
SELECT
  @source_rows_loaded AS source_rows_loaded,
  @source_distinct_customers AS source_distinct_customers,
  @inserted_customers AS inserted_customers,
  @inserted_invoice_rows AS inserted_invoices,
  @updated_invoice_rows AS updated_invoices,
  @skipped_conflict_rows AS skipped_conflict_rows,
  @skipped_customer_rows AS skipped_customer_rows,
  @deleted_invoice_item_rows AS deleted_existing_invoice_items,
  @inserted_invoice_item_rows AS inserted_invoice_items;

-- Skipped rows due to invoice-number/POS-reference conflicts
SELECT
  source_row_num,
  document_no,
  pos_reference,
  invoice_by_number_id,
  invoice_by_pos_id
FROM tmp_invoice_resolution
WHERE resolution_status = 'skip_conflict'
ORDER BY source_row_num;

-- Skipped rows due to unresolved customer matching
SELECT
  r.source_row_num,
  s.customer_name,
  s.customer_norm,
  cr.customer_resolution
FROM tmp_invoice_resolution r
JOIN tmp_sales_source s ON s.source_row_num = r.source_row_num
JOIN tmp_sales_customer_resolution cr ON cr.source_row_num = r.source_row_num
WHERE r.resolution_status = 'skip_customer'
ORDER BY r.source_row_num;

-- Breakdown by mapped status/payment type in the source range
SELECT
  status,
  payment_type,
  COUNT(*) AS row_count
FROM tmp_sales_source
GROUP BY status, payment_type
ORDER BY status, payment_type;

-- ROLLBACK; -- Uncomment for dry-run safety.
COMMIT;
