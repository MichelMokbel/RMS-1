# Order labels

## Decision

Extend the current POS print delivery queue rather than creating another retry and acknowledgement system. RMS renders a fixed size PDF label from a protected order snapshot. A local authenticated agent pulls the job and sends it to an allowlisted operating system printer queue.

The cloud server never connects directly to a printer on the restaurant network. The printer profile names the assigned terminal and logical queue, while the local agent maps that logical queue to an installed operating system printer.

## Default label

The label uses a single column hierarchy designed for narrow stock:

1. Layla Kitchen and the order number in the largest text.
2. Service date and time.
3. Customer name.
4. Delivery destination or pickup marker.
5. Item quantity followed by a shortened item description, one line per item where possible.
6. Copy number when more than one copy was requested.
7. A compact QR or Code 128 value containing only the order type and internal order ID.

Prices, discounts, invoice state, payment method, and phone number are excluded. Long text wraps or is shortened according to a deterministic rule. The preview warns when content exceeds the selected fixed media height. Continuous media computes a bounded page height from the actual lines and leaves the printer driver to cut after the page.

## Print actions

The first release supports one order print and one date batch print. It does not print automatically on payment, invoice, or order creation. This avoids duplicate or premature labels while the operation is run by one person.

The default is one label per order. The operator may choose a small bounded copy count for orders split across packages. Each physical copy carries its copy number. A batch skips cancelled orders and shows which jobs were already printed, queued, failed, or deliberately reprinted.

## Reliability

The label record and POS print job are created in one database transaction. Rendering finishes before enqueue. A unique server UUID makes a retried RMS action return the same job. The agent stores the job ID and claim token before printing, acknowledges after the operating system accepts the job, and never prints the same claim twice.

If acknowledgement is lost after the operating system accepts a job, the persisted local claim prevents a duplicate on redelivery. An explicit reprint uses a new label sequence and requires a reason. Failure retries are bounded, visible, and independent of order and finance state.

## Printer profiles

Profiles are configured in RMS Settings and begin inactive. Required fields are company, branch, name, department, verified model code, operating system queue name, connection description, resolution, media type, width, height or continuous bounds, and default copies. RMS provisions a dedicated print device automatically instead of asking the administrator for a POS terminal ID.

The administrator downloads one generated Windows setup script from the profile. It installs a native PowerShell agent as a system startup task, stores a token limited to print endpoints and one device, and creates the local queue allowlist from the profile. It downloads the pinned portable PDF renderer from its official HTTPS origin and verifies its checksum before use. The operator does not install Python or Bash, enter credentials, copy tokens, choose terminal IDs, or edit JSON.

A server profile cannot make the agent print to a queue outside the generated allowlist. Hardware changes invalidate verification and require a new setup download and physical test. Generating a replacement setup rotates the prior device token while the profile is inactive.

## Hardware adaptation

Brother QL 820NWB supports a 58 mm maximum printing width, 300 dpi output, USB, Wi Fi, Ethernet, Bluetooth, and an automatic cutter. The installed DK roll still decides the actual usable page dimensions.

The supplied BIXOLON name must be verified from the physical label. Official material was found for SLP DX220, which supports direct thermal stock up to 60 mm wide, with different printable widths and resolutions for DX220 and DX223. The build must not choose a resolution or printable width from the family name alone.

Both devices use their official Windows driver, USB, and fixed 57 mm by 37 mm label stock. RMS renders that exact PDF page. Manufacturer command languages remain adapter options only if driver printing proves unreliable during the physical test.
